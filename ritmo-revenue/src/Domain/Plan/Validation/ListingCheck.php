<?php

declare(strict_types=1);

namespace Ritmo\Domain\Plan\Validation;

use DateTimeImmutable;
use Ritmo\Domain\Config\RuleSet;
use Ritmo\Domain\Model\RoomType;
use Ritmo\Domain\Orders\OrderRelease;
use Ritmo\Domain\Plan\IssueSubject;
use Ritmo\Domain\Plan\ListingChange;
use Ritmo\Domain\Plan\ListingField;
use Ritmo\Domain\Plan\PlanIssue;
use Ritmo\Domain\Pricing\Floors;

/**
 * Changes to the listings: prices are corrected by percentages on dates, so a listing changes only
 * by an owner order in force that releases it; the base and the minimum move at most the weekly
 * limit from their values at the start of the week; the minimum never goes under the safety limit
 * and the base never under the minimum.
 *
 * Reference: validar_plano.Validador.validar_anuncio.
 */
final class ListingCheck
{
    public function __construct(
        private readonly RuleSet $rules,
        private readonly AuthorityCheck $authority,
        private readonly IssueLog $log,
    ) {
    }

    /**
     * @param list<ListingChange>                              $changes
     * @param array<string, RoomType>                          $rooms     room code => room type of the property
     * @param array<string, array{base?: float, min?: float}> $reference room code => listing values at the start of the week
     */
    public function check(array $changes, array $rooms, array $reference, DateTimeImmutable $today): void
    {
        $floors = new Floors($this->rules);
        foreach ($changes as $index => $change) {
            $issue = fn (bool $error, string $code, string $message, array $params = []) => $this->log->add($error, $code, $message, $index, $change->roomCode, null, $params, IssueSubject::Listing);
            $room = $rooms[$change->roomCode] ?? null;
            if ($room === null) {
                $issue(true, PlanIssue::UNKNOWN_ROOM, sprintf('Room "%s" is not a room type of this property.', $change->roomCode));
                continue;
            }
            if ($change->reasonCode === '') {
                $issue(true, PlanIssue::MISSING_REASON, 'The listing change has no reason code for the change log.');
            }
            $this->order($change, $index, $today, $issue);
            $this->limits($change, $room, $reference[$change->roomCode] ?? [], $floors->safetyLimit($room), $issue);
        }
    }

    private function order(ListingChange $change, int $index, DateTimeImmutable $today, \Closure $issue): void
    {
        if ($change->orderId === null) {
            $issue(true, PlanIssue::LISTING_NEEDS_ORDER, 'Prices are corrected by percentages on dates; the listing changes only by an owner order.');

            return;
        }
        $authority = $this->authority->resolve($index, $change->roomCode, $today, $change->orderId, IssueSubject::Listing);
        if ($authority->isValid() && !$authority->allows(OrderRelease::Listing)) {
            $issue(true, PlanIssue::ORDER_DOES_NOT_RELEASE, sprintf('Order %s does not release changes to the listing.', $change->orderId), ['order_id' => $change->orderId]);
        }
    }

    /**
     * @param array{base?: float, min?: float} $reference
     */
    private function limits(ListingChange $change, RoomType $room, array $reference, float $safety, \Closure $issue): void
    {
        $limit = $this->rules->weeklyListingChangeMax();
        $was = $reference[$change->field->value] ?? null;
        if ($change->field !== ListingField::Max && $was !== null && $was > 0) {
            $variation = abs($change->value - $was) / $was;
            if ($variation > $limit + 1e-9) {
                $issue(true, PlanIssue::LISTING_WEEKLY_CHANGE_ABOVE_LIMIT, sprintf('The %s moves %.0f%% from the start of the week (%.2f); the limit is %.0f%%.', $change->field->value, $variation * 100, $was, $limit * 100), [
                    'value' => $change->value,
                    'reference' => $was,
                    'limit' => $limit,
                ]);
            }
        }
        if ($change->field === ListingField::Min && $change->value < $safety) {
            $issue(true, PlanIssue::BELOW_SAFETY_LIMIT, sprintf('The minimum of %.2f is under the safety limit of %.2f.', $change->value, $safety), ['amount' => $change->value, 'safety_limit' => $safety]);
        }
        if ($change->field === ListingField::Base && $change->value < $room->min) {
            $issue(true, PlanIssue::LISTING_BASE_BELOW_MIN, sprintf('The base of %.2f is under the listing minimum of %.2f.', $change->value, $room->min), ['value' => $change->value, 'listing_min' => $room->min]);
        }
    }
}
