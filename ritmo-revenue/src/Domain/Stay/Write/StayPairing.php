<?php

declare(strict_types=1);

namespace Ritmo\Domain\Stay\Write;

use DateTimeImmutable;
use Ritmo\Domain\Calendar\Stay\PricingStayNight;
use Ritmo\Domain\Model\DateOverride;
use Ritmo\Domain\Model\Restriction;
use Ritmo\Domain\Plan\PlanItem;
use Ritmo\Domain\Support\Days;
use Ritmo\Domain\Write\State\ChangeField;

/**
 * Pairs every PMS change whose field the pricing system pushes (spec 06, 3.4 step 4; invariant
 * 11): a plan item of the same room night that sets the same value pairs it (its pricing step
 * goes first); else the pricing calendar read in this run already holding the value covers it
 * (pricingHolds); else a pricing item is made from the change (same value, same proof; folded
 * into the night's item when there is one) and pairs it. A change on a night beyond the pricing
 * write horizon cannot be paired: written alone, the next push would undo it (SW-X1).
 */
final class StayPairing
{
    private const ALL_OPEN = '1111111';

    public function __construct(private readonly PushedPmsValues $pushed)
    {
    }

    /**
     * @param list<PmsChangeEntry>                             $entries
     * @param array<string, array<string, PricingStayNight>>   $calendar  room => night => pricing night read in this run
     * @param array<string, array<string, DateOverride>|null>  $dateRules room => night => rule in force
     * @param DateTimeImmutable                                $lastPricingNight last night the pricing system may be written
     */
    public function pair(array $entries, MergeResult $items, array $calendar, array $dateRules, DateTimeImmutable $lastPricingNight): PairingResult
    {
        $out = $unpaired = $pairs = [];
        foreach ($entries as $entry) {
            if (!$entry->alsoPricing) {
                $out[] = $entry;
                continue;
            }
            $key = PlanItem::keyOf($entry->roomCode, $entry->night);
            $night = $calendar[$entry->roomCode][Days::key($entry->night)] ?? null;
            $rule = $dateRules[$entry->roomCode][Days::key($entry->night)] ?? null;
            $item = $items->item($key);
            if ($item !== null && self::itemSets($item, $entry, $night, $rule)) {
                $out[] = $entry->with(false, $key);
            } elseif ($this->holds($entry, $night, $rule)) {
                $out[] = $entry->with(true, null);
            } elseif ($entry->night <= $lastPricingNight && ($pair = StayItemFactory::pairOf($entry, self::masksFor($entry, $night, $rule))) !== null) {
                $pairs[] = $pair;
                $out[] = $entry->with(false, $key);
            } else {
                $out[] = $entry;
                $unpaired[] = $entry;
            }
        }
        $merged = $pairs === [] ? $items : StayItemMerger::merge($items->items, $pairs);

        return new PairingResult($out, new MergeResult($merged->items, [...$items->conflicts, ...($pairs === [] ? [] : $merged->conflicts)]), $unpaired);
    }

    private function holds(PmsChangeEntry $entry, ?PricingStayNight $night, ?DateOverride $rule): bool
    {
        $pushed = $this->pushed->of($entry->roomCode, $entry->night, $night, $rule);
        foreach ($entry->target->all() as $field => $value) {
            if ($pushed->has($field) && $pushed->get($field) !== $value) {
                return false;
            }
            if (!$pushed->has($field) && in_array($field, [ChangeField::PMS_MIN_STAY, ChangeField::PMS_RESTRICTION], true)) {
                return false;
            }
        }

        return true;
    }

    private static function itemSets(PlanItem $item, PmsChangeEntry $entry, ?PricingStayNight $night, ?DateOverride $rule): bool
    {
        $minStay = $entry->minStay();
        if ($minStay !== null && $item->minStay !== $minStay) {
            return false;
        }
        $restriction = $entry->restriction();
        if ($restriction === null) {
            return $minStay !== null;
        }
        if ($item->checkInMask === null && $item->checkOutMask === null) {
            return false;
        }
        $masks = self::masksInForce($night, $rule);
        $override = new DateOverride($entry->night, checkinMask: $item->checkInMask ?? $masks[0], checkoutMask: $item->checkOutMask ?? $masks[1]);

        return PushedPmsValues::restriction($entry->night, null, $override) === $restriction;
    }

    /**
     * The masks in force with the night's weekday set to the change's restriction; null when the
     * change sets no restriction.
     *
     * @return array{0: string, 1: string}|null
     */
    private static function masksFor(PmsChangeEntry $entry, ?PricingStayNight $night, ?DateOverride $rule): ?array
    {
        $restriction = $entry->restriction();
        if ($restriction === null) {
            return null;
        }
        [$in, $out] = self::masksInForce($night, $rule);
        $weekday = Days::weekday($entry->night);
        $in[$weekday] = in_array($restriction, [Restriction::NoCheckin, Restriction::NoCheckinCheckout], true) ? '0' : '1';
        $out[$weekday] = in_array($restriction, [Restriction::NoCheckout, Restriction::NoCheckinCheckout], true) ? '0' : '1';

        return [$in, $out];
    }

    /**
     * @return array{0: string, 1: string}
     */
    private static function masksInForce(?PricingStayNight $night, ?DateOverride $rule): array
    {
        $listing = $night !== null && $night->masksEnabled;

        return [
            $rule?->checkinMask ?? ($listing ? $night->checkinMask : null) ?? self::ALL_OPEN,
            $rule?->checkoutMask ?? ($listing ? $night->checkoutMask : null) ?? self::ALL_OPEN,
        ];
    }
}
