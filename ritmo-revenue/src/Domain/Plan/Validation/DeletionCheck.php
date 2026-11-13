<?php

declare(strict_types=1);

namespace Ritmo\Domain\Plan\Validation;

use DateTimeImmutable;
use Ritmo\Domain\Calendar\HolidayCalendar;
use Ritmo\Domain\Calendar\Stay\StayRules;
use Ritmo\Domain\Model\RoomType;
use Ritmo\Domain\Plan\IssueSubject;
use Ritmo\Domain\Plan\PlanDeletion;
use Ritmo\Domain\Plan\PlanIssue;
use Ritmo\Domain\Support\Days;

/**
 * The date rules a plan deletes before writing: a room of the property, a reason, one deletion per
 * room night, never in the past, never on a night an owner order keeps away (a manual freeze or a
 * fixed price, through AuthorityCheck: deleting the rule would undo the owner's change); and
 * warnings when the deletion drops what the night needs (its stay, on a closed day, a holiday or a
 * night that requires more than one night, unless the night waits for an owner decision; the
 * protection floor of a holiday).
 *
 * Reference: validar_plano.Validador.validar_apagar. The order guard on deletions is a plugin rule
 * (divergence F2 of docs/VERIFY.md): the reference checks the freeze on items only.
 */
final class DeletionCheck
{
    public function __construct(
        private readonly HolidayCalendar $calendar,
        private readonly ?StayRules $stay,
        private readonly IssueLog $log,
        private readonly ?AuthorityCheck $authority = null,
    ) {
    }

    /**
     * @param list<PlanDeletion>     $deletions
     * @param array<string, RoomType> $rooms room code => room type of the property
     */
    public function check(array $deletions, array $rooms, DateTimeImmutable $today): void
    {
        $seen = [];
        foreach ($deletions as $index => $deletion) {
            $issue = fn (bool $error, string $code, string $message, array $params = []) => $this->log->add($error, $code, $message, $index, $deletion->roomCode, $deletion->date, $params, IssueSubject::Deletion);
            if (!isset($rooms[$deletion->roomCode])) {
                $issue(true, PlanIssue::UNKNOWN_ROOM, sprintf('Room "%s" is not a room type of this property.', $deletion->roomCode));
                continue;
            }
            if (isset($seen[$deletion->key()])) {
                $issue(true, PlanIssue::DUPLICATE_ITEM, 'There is already a deletion for this room night.', ['first_item' => $seen[$deletion->key()]]);
            }
            $seen[$deletion->key()] ??= $index;
            if ($deletion->reasonCode === '') {
                $issue(true, PlanIssue::MISSING_REASON, 'The deletion has no reason code for the change log.');
            }
            if (Days::diff($today, $deletion->date) < 0) {
                $issue(true, PlanIssue::PAST_DATE, 'The night is in the past.');
            }
            $this->authority?->resolve($index, $deletion->roomCode, $deletion->date, null, IssueSubject::Deletion);
            $this->dropped($deletion, $issue);
        }
    }

    private function dropped(PlanDeletion $deletion, \Closure $issue): void
    {
        $holiday = $this->calendar->holidayOn($deletion->date);
        $required = $this->stay?->requiredStay($deletion->roomCode, $deletion->date);
        $closed = $this->stay?->closedDay($deletion->roomCode, $deletion->date);
        $held = $holiday === null && $closed === null && $this->stay?->isHeld($deletion->roomCode, $deletion->date) === true;
        if (($closed !== null || $holiday !== null || ($required ?? 0) > 1) && !$held) {
            $issue(false, PlanIssue::DELETE_REMOVES_STAY, sprintf('Deleting the date rule drops the stay of %s nights the night requires: write the stay again in the same plan.', $required ?? '?'), ['required' => $required]);
        }
        if ($holiday !== null) {
            $issue(false, PlanIssue::DELETE_REMOVES_HOLIDAY_FLOOR, sprintf('Deleting a date rule of %s also drops its protection floor: write the floor again.', $holiday->name), ['period' => $holiday->name]);
        }
    }
}
