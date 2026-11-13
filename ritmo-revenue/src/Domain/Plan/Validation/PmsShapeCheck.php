<?php

declare(strict_types=1);

namespace Ritmo\Domain\Plan\Validation;

use DateTimeImmutable;
use Ritmo\Domain\Model\RoomType;
use Ritmo\Domain\Plan\IssueSubject;
use Ritmo\Domain\Plan\PlanIssue;
use Ritmo\Domain\Stay\Write\PmsChangeEntry;
use Ritmo\Domain\Support\Days;

/**
 * The PMS changes a plan carries, as the seal needs them: a room of the property mapped to the
 * PMS, a night from today on and inside stay.write.horizon_days, one change per room, night and
 * field. What a change may set and why (pairing, orphan proof, frozen nights, the cap) was checked
 * before by Stay\Write\PmsChangeCheck; only the changes it accepted reach the plan.
 */
final class PmsShapeCheck
{
    public function __construct(private readonly IssueLog $log, private readonly int $horizonDays)
    {
    }

    /**
     * @param list<PmsChangeEntry>    $changes
     * @param array<string, RoomType> $rooms room code => room type of the property
     */
    public function check(array $changes, array $rooms, DateTimeImmutable $today): void
    {
        $seen = [];
        foreach ($changes as $index => $change) {
            $issue = fn (string $code, string $message) => $this->log->add(true, $code, $message, $index, $change->roomCode, $change->night, [], IssueSubject::Pms);
            $room = $rooms[$change->roomCode] ?? null;
            if ($room === null || $room->pmsRef === '') {
                $issue(PlanIssue::PMS_ROOM_UNKNOWN, 'The room is not a room of this property mapped to the PMS.');
                continue;
            }
            $daysOut = Days::diff($today, $change->night);
            if ($daysOut < 0) {
                $issue(PlanIssue::PMS_PAST_NIGHT, 'The night is in the past.');
            } elseif ($daysOut >= $this->horizonDays) {
                $issue(PlanIssue::PMS_BEYOND_HORIZON, 'The night is beyond the nights the stay writes may change.');
            }
            foreach ($change->fieldKeys() as $key) {
                if (isset($seen[$key])) {
                    $issue(PlanIssue::PMS_DUPLICATE, 'There is already a change of this field on this room night.');
                }
                $seen[$key] = true;
            }
        }
    }
}
