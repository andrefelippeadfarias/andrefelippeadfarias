<?php

declare(strict_types=1);

namespace Ritmo\Application\Write\Gate;

use Ritmo\Domain\Plan\ListingChange;
use Ritmo\Domain\Plan\Plan;
use Ritmo\Domain\Plan\PlanDeletion;
use Ritmo\Domain\Plan\PlanItem;
use Ritmo\Domain\Support\Days;

/**
 * The content hash of a plan: SHA-256 of the canonical JSON of what it changes (room, night,
 * values, masks, the order it cites; deletions; listing changes), never of explanations. An
 * approval names a plan by this hash, so a plan that changed after the approval is not covered.
 */
final class PlanHash
{
    public static function of(Plan $plan): string
    {
        $items = array_map(static fn (PlanItem $i): array => [
            'room' => $i->roomCode, 'night' => Days::key($i->date), 'min' => $i->minPrice, 'max' => $i->maxPrice,
            'stay' => $i->minStay, 'in' => $i->checkInMask, 'out' => $i->checkOutMask, 'order' => $i->orderId,
        ], $plan->items);
        $deletions = array_map(static fn (PlanDeletion $d): array => ['room' => $d->roomCode, 'night' => Days::key($d->date)], $plan->deletions);
        $listing = array_map(static fn (ListingChange $c): array => ['room' => $c->roomCode, 'field' => $c->field->value, 'value' => $c->value, 'order' => $c->orderId], $plan->listingChanges);

        return hash('sha256', json_encode([
            'property' => $plan->propertyId,
            'today' => Days::key($plan->today),
            'items' => self::sorted($items),
            'deletions' => self::sorted($deletions),
            'listing' => self::sorted($listing),
        ], JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
    }

    /**
     * @param list<array<string, mixed>> $entries
     *
     * @return list<array<string, mixed>> in the order of their JSON text
     */
    private static function sorted(array $entries): array
    {
        $keyed = [];
        foreach ($entries as $entry) {
            $keyed[json_encode($entry, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION)] = $entry;
        }
        ksort($keyed, SORT_STRING);

        return array_values($keyed);
    }
}
