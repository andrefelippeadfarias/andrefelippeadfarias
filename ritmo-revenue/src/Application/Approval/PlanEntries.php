<?php

declare(strict_types=1);

namespace Ritmo\Application\Approval;

use InvalidArgumentException;
use Ritmo\Domain\Plan\Plan;
use Ritmo\Domain\Plan\PlanItem;
use Ritmo\Domain\Stay\Write\PmsChangeEntry;
use Ritmo\Domain\Support\Days;
use Ritmo\Domain\Support\Num;

/**
 * The entries of a plan as a person approves them, and the hash that names the plan: SHA-256 of
 * the canonical JSON of its entries (rooms, nights, kinds, sent fields, orders), never of the
 * explanations.
 */
final class PlanEntries
{
    /** Prefix of the values a PMS change found before it. */
    public const BEFORE = 'before:';

    private function __construct()
    {
    }

    /**
     * @param array<string, float> $bases room => listing base the percentages are over
     *
     * @return list<PlanEntry> items, then deletions, then listing changes, then PMS changes, in plan order
     *
     * @throws InvalidArgumentException when the base of an item's room is missing
     */
    public static function of(Plan $plan, array $bases): array
    {
        $entries = array_map(static fn (PlanItem $item): PlanEntry => new PlanEntry(PlanEntry::ITEM, $item->roomCode, Days::key($item->date), self::fields($item, $bases), $item->orderId), $plan->items);
        foreach ($plan->deletions as $deletion) {
            $entries[] = new PlanEntry(PlanEntry::DELETION, $deletion->roomCode, Days::key($deletion->date));
        }
        foreach ($plan->listingChanges as $change) {
            $entries[] = new PlanEntry(PlanEntry::LISTING, $change->roomCode, $change->field->value, ['value' => $change->value], $change->orderId);
        }
        foreach ($plan->pmsChanges as $change) {
            $entries[] = self::pms($change);
        }

        return $entries;
    }

    /**
     * @param list<PlanEntry> $entries
     */
    public static function hash(array $entries): string
    {
        $rows = array_map(static fn (PlanEntry $e): array => $e->toArray(), $entries);
        usort($rows, static fn (array $a, array $b): int => strcmp($a['key'], $b['key']));

        return hash('sha256', (string) json_encode($rows, JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_SLASHES));
    }

    /**
     * A PMS change by its night and the fields it sets ("2027-03-12|pms_min_stay"), with the
     * values read before ("before:pms_min_stay"): a PMS that changed since is another entry. A
     * value that lifts a limit, or a field never read, is "none".
     */
    public static function pms(PmsChangeEntry $change): PlanEntry
    {
        $fields = $before = [];
        foreach ($change->target->all() as $field => $value) {
            $fields[$field] = $value ?? 'none';
            $now = $change->before->get($field);
            $before[self::BEFORE . $field] = $now ?? 'none';
        }
        ksort($fields);

        return new PlanEntry(PlanEntry::PMS, $change->roomCode, Days::key($change->night) . '|' . implode(',', array_keys($fields)), $fields + $before, $change->orderId);
    }

    /**
     * What the item sends: floor and ceiling as the whole percentages the payload writes.
     *
     * @param array<string, float> $bases
     *
     * @return array<string, int|string>
     */
    private static function fields(PlanItem $item, array $bases): array
    {
        $base = $bases[$item->roomCode] ?? null;
        if ($base === null && ($item->minPrice !== null || $item->maxPrice !== null)) {
            throw new InvalidArgumentException(sprintf('The base of room "%s" is missing.', $item->roomCode));
        }
        $floor = $item->minPrice === null ? null : Num::floorToPercent($item->minPrice, (float) $base);
        $ceiling = $item->maxPrice === null ? null : Num::ceilingToPercent($item->maxPrice, (float) $base);
        if ($floor !== null && $ceiling !== null && $ceiling < $floor) {
            $ceiling = $floor;
        }

        return array_filter([
            'floor_pct' => $floor,
            'ceiling_pct' => $ceiling,
            'min_stay' => $item->minStay,
            'check_in_mask' => $item->checkInMask,
            'check_out_mask' => $item->checkOutMask,
        ], static fn (int|string|null $v): bool => $v !== null);
    }
}
