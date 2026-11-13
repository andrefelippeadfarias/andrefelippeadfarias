<?php

declare(strict_types=1);

namespace Ritmo\Application\Write\Journal;

use Ritmo\Domain\Model\DateOverride;
use Ritmo\Domain\Model\InventoryNight;
use Ritmo\Domain\Support\Num;

/**
 * The comparable form of a night in each system, the one the journal stores (before, intended,
 * observed) and the read-back compares: only the fields the write path sets.
 * - Pricing date rule: min_price and max_price (whole percentages), min_stay, check_in,
 *   check_out; "fixed" marks a rule with amounts of its own (never ours). Null: no rule.
 * - PMS night: minStay, maxStay, override (the API's restriction word), numAvail (net).
 */
final class NightRule
{
    private const RESTRICTIONS = [
        'none' => 'none',
        'blackout' => 'blackout',
        'no_checkin' => 'noCheckIn',
        'no_checkout' => 'noCheckOut',
        'no_checkin_checkout' => 'noCheckInOrCheckOut',
    ];

    /**
     * @return array<string, int|string|bool|null>|null
     */
    public static function ofOverride(?DateOverride $override): ?array
    {
        if ($override === null) {
            return null;
        }
        $rule = [
            'min_price' => $override->minPercent === null ? null : Num::roundInt($override->minPercent),
            'max_price' => $override->maxPercent === null ? null : Num::roundInt($override->maxPercent),
            'min_stay' => $override->minStay,
            'check_in' => $override->checkinMask,
            'check_out' => $override->checkoutMask,
        ];
        if ($override->price !== null || $override->minFixed !== null || $override->maxFixed !== null) {
            $rule['fixed'] = true;
        }

        return $rule;
    }

    /**
     * The fields one written date rule sets (from the API's fields of the body).
     *
     * @param array<string, string> $entry
     *
     * @return array<string, int|string>
     */
    public static function sent(array $entry): array
    {
        $sent = [];
        foreach (['min_price', 'max_price', 'min_stay'] as $field) {
            if (isset($entry[$field])) {
                $sent[$field] = (int) $entry[$field];
            }
        }
        foreach (['check_in', 'check_out'] as $field) {
            if (isset($entry[$field])) {
                $sent[$field] = $entry[$field];
            }
        }

        return $sent;
    }

    /**
     * The rule after a write merges the sent fields into the stored rule (the pricing system
     * keeps the fields not sent).
     *
     * @param array<string, mixed>|null $before
     * @param array<string, mixed>      $sent
     *
     * @return array<string, mixed>
     */
    public static function merged(?array $before, array $sent): array
    {
        $empty = ['min_price' => null, 'max_price' => null, 'min_stay' => null, 'check_in' => null, 'check_out' => null];

        return array_merge($empty, $before ?? [], $sent);
    }

    /**
     * @return array<string, int|string|null>
     */
    public static function ofInventory(InventoryNight $night): array
    {
        return [
            'minStay' => $night->minStay,
            'maxStay' => $night->maxStay,
            'override' => self::RESTRICTIONS[$night->restriction->value],
            'numAvail' => $night->available,
        ];
    }

    /**
     * True when the current state holds every compared field of the intended one.
     *
     * @param array<string, mixed>|null $current
     * @param array<string, mixed>|null $intended null: no rule
     * @param list<string>              $fields   the fields to compare
     */
    public static function holds(?array $current, ?array $intended, array $fields): bool
    {
        if ($intended === null || $current === null) {
            return $intended === $current;
        }
        foreach ($fields as $field) {
            if (($current[$field] ?? null) !== ($intended[$field] ?? null)) {
                return false;
            }
        }

        return true;
    }
}
