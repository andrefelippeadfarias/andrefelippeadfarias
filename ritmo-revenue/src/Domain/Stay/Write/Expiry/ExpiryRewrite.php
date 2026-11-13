<?php

declare(strict_types=1);

namespace Ritmo\Domain\Stay\Write\Expiry;

use Ritmo\Domain\Model\DateOverride;
use Ritmo\Domain\Model\RoomType;
use Ritmo\Domain\Plan\PlanDeletion;
use Ritmo\Domain\Plan\PlanItem;
use Ritmo\Domain\Support\Days;
use Ritmo\Domain\Support\Num;

/**
 * W36 (spec 06, D2): a write on a night whose stored date rule has a lead-time expiry never merges
 * into it (the merged rule would keep expiring). Every item on such a night that is not already a
 * delete + rewrite group becomes one: the rule is deleted and the item rewritten without the
 * expiry, inheriting the minimum stay, the arrival and departure masks, the floor and the ceiling
 * it does not set (as amounts over the room's base). Runs in the assembly after the ceiling
 * release; tonight's guard applies to the rewrite as to any group.
 *
 * Divergence X1: the reference converts only a ceiling release (pace.ajustar_tetos) and deletes
 * by hand otherwise (SKILL.md step 6); the plugin always converts.
 */
final class ExpiryRewrite
{
    public const REASON = 'EXPIRY_REWRITE';

    /**
     * @param list<PlanItem>                               $items
     * @param list<PlanDeletion>                           $deletions
     * @param array<string, array<string, DateOverride>>   $rules     room => night => rule stored now
     * @param array<string, array<string, ExpiryWindow>>   $expiries  room => night => the rule's expiry
     * @param array<string, RoomType>                      $rooms     room code => room
     *
     * @return array{0: list<PlanItem>, 1: list<PlanDeletion>}
     */
    public static function apply(array $items, array $deletions, array $rules, array $expiries, array $rooms): array
    {
        $grouped = [];
        foreach ($deletions as $deletion) {
            $grouped[$deletion->key()] = true;
        }
        $out = [];
        foreach ($items as $item) {
            $night = Days::key($item->date);
            $rule = $rules[$item->roomCode][$night] ?? null;
            $room = $rooms[$item->roomCode] ?? null;
            if ($rule === null || $room === null || !isset($expiries[$item->roomCode][$night]) || isset($grouped[$item->key()])) {
                $out[] = $item;
                continue;
            }
            $deletions[] = new PlanDeletion($item->roomCode, $item->date, self::REASON);
            $grouped[$item->key()] = true;
            $out[] = self::rewrite($item, $rule, $room);
        }

        return [$out, $deletions];
    }

    private static function rewrite(PlanItem $item, DateOverride $rule, RoomType $room): PlanItem
    {
        return new PlanItem(
            $item->roomCode,
            $item->date,
            $item->minPrice ?? self::amount($rule->minPercent, $room),
            $item->maxPrice ?? self::amount($rule->maxPercent, $room),
            $item->minStay ?? $rule->minStay,
            $item->reasonCode,
            $item->reasonParams + ['rewrite' => self::REASON],
            $item->before,
            $item->checkInMask ?? $rule->checkinMask,
            $item->checkOutMask ?? $rule->checkoutMask,
            $item->orderId,
            $item->orphan,
            $item->marketEvidence,
            false,
            $item->reasonText,
            $item->readsOccupancy,
        );
    }

    /**
     * A percentage over the base as an amount (the payload turns it back into the same percentage).
     */
    private static function amount(?float $percent, RoomType $room): ?float
    {
        return $percent === null ? null : Num::percentToAmount($percent, $room->base, 2);
    }
}
