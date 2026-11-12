<?php

declare(strict_types=1);

namespace Ritmo\Domain\Pricing\Protection;

use Ritmo\Domain\Market\AnchorLookup;
use Ritmo\Domain\Model\DateOverride;
use Ritmo\Domain\Model\MarketAnchor;
use Ritmo\Domain\Model\PriceNight;
use Ritmo\Domain\Model\RoomType;
use Ritmo\Domain\Orders\OwnerOrder;
use Ritmo\Domain\Support\Num;

/**
 * Protection of a sold-out (or overbooked) room night that no sales ladder governs: a floor high
 * in the market with the ceiling released, so a cancellation never reopens the night cheap.
 *
 * floor = min(max(market value at the protection percentile / public factor, standard floor,
 * extra floor), cap), rounded up; cap = the relation ceiling or the listing maximum. The ceiling
 * is the listing maximum (free) or, with a relation ceiling, that ceiling rounded down.
 *
 * The protection only RAISES the floor:
 * - no item when the live floor is already at the floor (within the tolerance) and there is no
 *   ceiling to release;
 * - when a ceiling must go and the live floor is above the protection floor, the live floor stays
 *   and only the ceiling goes (a ladder price fixed as floor = ceiling keeps its floor when the
 *   night sells out), unless that floor was written by a retired rule or no live override is known;
 * - no item while the live floor is unknown: a summarised calendar read may hide a floor of the
 *   pricing system itself.
 *
 * The standard floor never includes the selling floor of a dated order (a floor meant to sell an
 * empty night must not protect a sold one); the floor of a target order enters only as an extra
 * floor ({@see extraFloor()}).
 *
 * Reference: pace.protecao_esgotada (with the fix of 12 Nov) and its caller in pace._plano.
 */
final class SoldOutProtection
{
    public function __construct(
        private readonly ProtectionConfig $config,
        private readonly float $tolerance,
        private readonly float $moneySlack = 1.0,
        private readonly float $percentSlack = 0.01,
    ) {
    }

    /**
     * @param PriceNight      $live            the calendar night (price in force, floor and ceiling when known)
     * @param DateOverride|null $override      the live date override of the night, when known
     * @param float           $publicFactor    the configured public factor of the room's property (public / sent)
     * @param float           $standardFloor   the standard floor of the night, sent plan
     * @param float|null      $extraFloor      a higher floor the night must keep (relation, target order), sent plan
     * @param float|null      $relationCeiling the ceiling a room relation sets, sent plan
     */
    public function propose(
        RoomType $room,
        PriceNight $live,
        ?DateOverride $override,
        ?MarketAnchor $anchor,
        float $publicFactor,
        float $standardFloor,
        ?float $extraFloor = null,
        ?float $relationCeiling = null,
    ): ProtectionOutcome {
        if (!$this->config->enabled) {
            return ProtectionOutcome::none(ProtectionOutcome::DISABLED);
        }
        if ($anchor === null) {
            return ProtectionOutcome::none(ProtectionOutcome::NO_ANCHOR);
        }
        $marketPublic = AnchorLookup::valueAt($anchor, $this->config->percentile);
        $marketSent = $marketPublic / $publicFactor;
        $hasRelationCeiling = $relationCeiling !== null && $relationCeiling > 0;
        $cap = $hasRelationCeiling ? $relationCeiling : $room->max;
        $floor = min(max($marketSent, $standardFloor, $extraFloor ?? 0.0), $cap);

        $liveFloor = LiveBounds::floor($live, $override, $room->base);
        if ($liveFloor === null) {
            return ProtectionOutcome::none(ProtectionOutcome::UNKNOWN_LIVE_FLOOR);
        }
        $liveCeiling = LiveBounds::ceiling($live, $override, $room->base) ?: $room->max;
        $floorInPlace = $liveFloor >= $floor * (1 - $this->tolerance);
        $hasCeiling = $liveCeiling < $room->max - $this->moneySlack || ($override !== null && ($override->maxPercent !== null || $override->maxFixed !== null));
        if ($floorInPlace && !$hasCeiling) {
            return ProtectionOutcome::none(ProtectionOutcome::ALREADY_IN_PLACE);
        }

        $source = $this->source($floor, $marketSent, $standardFloor, $extraFloor);
        $minPrice = (float) Num::ceilInt($floor);
        if ($this->config->keepLiveFloorOnRelease && $hasCeiling && $liveFloor > $minPrice && !$this->isRetired($override)) {
            $minPrice = Num::roundHalfEven($liveFloor, 2);
            $source = FloorSource::LiveFloorKept;
        }
        $maxPrice = $hasRelationCeiling ? (float) Num::floorInt(max($cap, $minPrice)) : $room->max;
        if ($hasRelationCeiling && $floorInPlace && abs($liveCeiling - $maxPrice) <= $room->base * $this->percentSlack + $this->moneySlack) {
            return ProtectionOutcome::none(ProtectionOutcome::ALREADY_IN_PLACE);
        }

        return ProtectionOutcome::item(new ProtectionItem(
            $room->code,
            $live->date,
            $minPrice,
            $maxPrice,
            $live->price,
            $source,
            !$hasRelationCeiling,
            $live->sold,
            $live->total,
            $this->config->percentile,
            $marketPublic,
        ));
    }

    /**
     * The extra floor of a sold-out night: the floor of the room's relations and the floor of the
     * target order of the night, whichever is higher, as configured. Null when neither applies.
     */
    public function extraFloor(?float $relationFloor, ?OwnerOrder $targetOrder, string $room): ?float
    {
        $extra = $this->config->useRelationBounds ? $relationFloor : null;
        $orderFloor = $this->config->useTargetOrderFloor ? $targetOrder?->floorFor($room) : null;
        if ($orderFloor !== null && $orderFloor > 0) {
            $extra = max($extra ?? 0.0, $orderFloor);
        }

        return $extra;
    }

    /**
     * The relation ceiling to honour, or null when relation bounds are switched off.
     */
    public function relationCeiling(?float $ceiling): ?float
    {
        return $this->config->useRelationBounds ? $ceiling : null;
    }

    /**
     * In a light run, whether a night this far away is protected now (otherwise the next full
     * run does it).
     */
    public function inLightRunReach(int $daysOut): bool
    {
        return $this->config->lightRunMaxDays === null || $daysOut <= $this->config->lightRunMaxDays;
    }

    private function source(float $floor, float $marketSent, float $standardFloor, ?float $extraFloor): FloorSource
    {
        if ($extraFloor !== null && $extraFloor != 0.0 && $floor == $extraFloor && $floor > $marketSent) {
            return FloorSource::ExtraFloor;
        }
        if ($floor == $standardFloor && $floor > $marketSent) {
            return FloorSource::StandardFloor;
        }

        return FloorSource::MarketPercentile;
    }

    /**
     * A live floor is not kept when no override is known, or when the override was written by a
     * retired rule.
     */
    private function isRetired(?DateOverride $override): bool
    {
        if ($override === null) {
            return true;
        }
        foreach ($this->config->retiredFloorExemptions as $exemption) {
            if (str_contains($override->reason, $exemption)) {
                return false;
            }
        }
        foreach ($this->config->retiredFloorMarkers as $marker) {
            if (str_contains($override->reason, $marker)) {
                return true;
            }
        }

        return false;
    }
}
