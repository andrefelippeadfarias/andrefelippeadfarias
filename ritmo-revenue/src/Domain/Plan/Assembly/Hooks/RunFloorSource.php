<?php

declare(strict_types=1);

namespace Ritmo\Domain\Plan\Assembly\Hooks;

use DateTimeImmutable;
use Ritmo\Domain\Config\RuleSet;
use Ritmo\Domain\Market\AnchorLookup;
use Ritmo\Domain\Market\PriceFactor;
use Ritmo\Domain\Model\PricePlan;
use Ritmo\Domain\Orders\OrderRelease;
use Ritmo\Domain\Plan\Assembly\RunCalendar;
use Ritmo\Domain\Pricing\Floors;
use Ritmo\Domain\Pricing\Ladder\LastMinuteMarketFloor;
use Ritmo\Domain\Pricing\OrderSalesRamp;
use Ritmo\Domain\Pricing\PaceMeter;
use Ritmo\Domain\Pricing\PaceReading;
use Ritmo\Domain\Pricing\StepSize;
use Ritmo\Domain\Pricing\Terms\NightTermsResolver;
use Ritmo\Domain\Relations\Neutral\HardFloorSource;
use Ritmo\Domain\Relations\Port\BetweenHints;
use Ritmo\Domain\Relations\Port\FloorInForce;
use Ritmo\Domain\Relations\Port\FloorSource;
use Ritmo\Domain\Relations\Relation;
use Ritmo\Domain\Support\Days;

/**
 * The floors of a room night as the run's orders and strategy set them, for the relations.
 *
 * Reference: the hooks pace.coerencia computes inline (piso_com_ordem, pisos_enviados, the
 * floors of an order that releases going below the standard floor, piso_semana_mercado,
 * piso_duro_do_plano and the target of a target order).
 */
final class RunFloorSource implements FloorSource
{
    private readonly PaceMeter $meter;

    private readonly PriceFactor $factors;

    private readonly Floors $floors;

    private readonly StepSize $steps;

    public function __construct(
        private readonly RuleSet $rules,
        private readonly RunCalendar $calendar,
        private readonly NightTermsResolver $terms,
        private readonly HardFloorSource $hard,
        private readonly AnchorLookup $anchors,
        private readonly ?LastMinuteMarketFloor $marketFloor,
    ) {
        $this->meter = new PaceMeter($rules);
        $this->factors = new PriceFactor($rules);
        $this->floors = new Floors($rules, $this->factors);
        $this->steps = new StepSize($rules);
    }

    public function inForce(string $room, DateTimeImmutable $night): FloorInForce
    {
        $set = $this->terms->floorWithOrders($this->room($room), $night, $this->calendar->inputs()->orders);

        return new FloorInForce($set['floor']->amount, $set['order'] !== null);
    }

    public function lowestAllowed(string $room, DateTimeImmutable $night): float
    {
        $type = $this->room($room);
        $set = $this->terms->floorWithOrders($type, $night, $this->calendar->inputs()->orders);
        $order = $set['order'];
        if ($order === null || !$order->releases(OrderRelease::BelowStandardFloor)) {
            return $this->terms->standardFloor($type, $night)->amount;
        }
        $values = [$set['floor']->amount, ...($order->terms->descentFloors[$room] ?? [])];
        if (isset($order->terms->fallbackFloors[$room])) {
            $values[] = $order->terms->fallbackFloors[$room];
        }

        return max(min($values), $this->floors->safetyLimit($type));
    }

    public function betweenHints(Relation $relation, string $room, DateTimeImmutable $night): BetweenHints
    {
        $inputs = $this->calendar->inputs();
        $type = $this->room($room);
        $daysOut = Days::diff($inputs->today, $night);
        $target = $inputs->orders->targetFor($room, $night);
        $ramp = $this->rules->salesRampTop();
        $ladder = $ramp !== null && $target !== null && $target->terms->salesRamp;
        $strong = false;
        $orderRamp = null;
        $reading = null;
        if ($target !== null) {
            [$sold, $total] = $this->calendar->paceBasis($type, $night);
            $reading = $this->meter->measure($this->rules->nightType($night), $daysOut, $sold, $total);
            $strong = !$this->terms->isWeakNight($target, $reading->status, $sold, $total);
            $orderRamp = $ladder ? $this->terms->rampFor($target, $type, $night, $daysOut, $reading->status, $sold, $total) : null;
            if (!$ladder && $strong) {
                $target = null;
            }
        }
        $floor = $this->terms->floorWithOrders($type, $night, $target !== null ? $inputs->orders : $inputs->orders->withoutTargets())['floor']->amount;
        if ($orderRamp !== null && $reading !== null) {
            [$floor, $descended] = $this->rampFloor($orderRamp, $floor, $room, $night, $daysOut, $reading);
            $strong = $strong && !$descended;
        }
        $standard = $floor;
        $market = $this->marketFloor?->floorFor($room, $night, $daysOut, $inputs->orders);
        if ($market !== null && $market['amount'] < $floor) {
            $floor = $market['amount'];
        }
        $ceiling = null;
        $anchor = $target !== null ? $inputs->anchors->forNight($night) : null;
        if ($target !== null && $anchor !== null) {
            $percentile = $inputs->orders->percentileOf($target);
            if ($orderRamp !== null && $ramp > $percentile) {
                $percentile += ($ramp - $percentile) * $orderRamp->share;
            }
            $ceiling = AnchorLookup::valueAt($anchor, $percentile) / $this->factors->factor($type, PricePlan::PublicRate, $this->rules->nightType($night));
        }

        return new BetweenHints($floor, $standard, $market !== null ? $market['order'] : null, $ceiling, $ladder && $strong);
    }

    /**
     * The floor of a "between" room on its order's sales ramp: the fallback floor and the descent
     * step lower it (no one-step limit here: the room stays above its lower reference, which
     * already descends one step at a time); back on its curve it climbs by at most one step limit.
     * Returns the floor and whether a descent step applied.
     *
     * @return array{float, bool}
     */
    private function rampFloor(OrderSalesRamp $ramp, float $floor, string $room, DateTimeImmutable $night, int $daysOut, PaceReading $reading): array
    {
        if ($ramp->fallbackFloor !== null) {
            $floor = min($floor, $ramp->fallbackFloor);
        }
        $descent = $ramp->descentFloor($daysOut, $reading);
        if ($descent !== null) {
            return [min($floor, $descent), true];
        }
        $current = $this->calendar->row($room, $night)?->price;
        $limit = $current === null ? null : $ramp->returnLimit($daysOut, $current, $floor, $this->steps->maxPercent($daysOut));

        return [$limit ?? $floor, false];
    }

    public function switchFloor(string $room, DateTimeImmutable $night): float
    {
        return $this->hard->switchFloor($room, $night);
    }

    public function ruleFloor(string $room, DateTimeImmutable $night, ?DateTimeImmutable $today): ?float
    {
        return $this->hard->ruleFloor($room, $night, $today);
    }

    public function safetyException(string $room, DateTimeImmutable $night): ?float
    {
        return $this->hard->safetyException($room, $night);
    }

    private function room(string $code): \Ritmo\Domain\Model\RoomType
    {
        return $this->calendar->inputs()->rooms[$code];
    }
}
