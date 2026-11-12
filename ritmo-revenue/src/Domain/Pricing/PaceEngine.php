<?php

declare(strict_types=1);

namespace Ritmo\Domain\Pricing;

use DateTimeImmutable;
use Ritmo\Domain\Calendar\HolidayCalendar;
use Ritmo\Domain\Config\RuleSet;
use Ritmo\Domain\Market\AnchorBuilder;
use Ritmo\Domain\Market\AnchorLookup;
use Ritmo\Domain\Market\PriceFactor;
use Ritmo\Domain\Model\MarketAnchor;
use Ritmo\Domain\Model\NightType;
use Ritmo\Domain\Model\PaceStatus;
use Ritmo\Domain\Model\PricePlan;
use Ritmo\Domain\Model\RoomType;
use Ritmo\Domain\Support\Clock;
use Ritmo\Domain\Support\Days;
use Ritmo\Domain\Support\Num;

/**
 * The pace engine: one price decision per room night.
 *
 * For a night D days away it reads where the night is against its expected sales curve, takes
 * the market percentile that status aims at, converts it to the sent plan and moves the price
 * one limited step towards it, never under the hard floor nor over the listing maximum.
 *
 * Fixed rules: the price of tonight never goes up; a night selling ahead of its curve never goes
 * down; one step per night per day and direction (the last step of the ledger comes in with the
 * context). A sold-out night is left alone in this milestone.
 *
 * Reference: the core of pace._plano with pace.cadencia_permite and pace.limitar_ao_maximo,
 * without owner orders, the sales ladder and the relations between rooms. The order of the float
 * operations is the reference's and must not change.
 */
final class PaceEngine
{
    /** A second step in the same direction waits this long after the previous one. */
    public const STEP_INTERVAL_SECONDS = 86400;

    /** Slack of the comparisons against the listing maximum, whose amounts have two decimals. */
    private const CENT = 0.01;

    private readonly PaceMeter $meter;

    private readonly StepSize $steps;

    private readonly Floors $floors;

    private readonly PriceFactor $factors;

    private readonly AnchorBuilder $anchors;

    private readonly TargetPercentile $targets;

    private readonly HolidayCalendar $calendar;

    public function __construct(private readonly RuleSet $rules, private readonly Clock $clock)
    {
        $this->factors = new PriceFactor($rules);
        $this->meter = new PaceMeter($rules);
        $this->steps = new StepSize($rules);
        $this->floors = new Floors($rules, $this->factors);
        $this->anchors = new AnchorBuilder($rules);
        $this->targets = new TargetPercentile($rules);
        $this->calendar = new HolidayCalendar($rules);
    }

    public function decide(NightContext $context): PaceDecision
    {
        $today = $this->clock->today($context->property->timezone);
        $daysOut = Days::diff($today, $context->night);
        $type = $this->calendar->nightType($context->night);
        $common = [
            'roomCode' => $context->room->code,
            'night' => $context->night,
            'daysOut' => $daysOut,
            'nightType' => $type,
            'currentPrice' => $context->currentPrice,
            'anchor' => $context->anchor,
        ];
        $trace = [self::entry(RuleCode::NIGHT, [
            'night_type' => $type->value,
            'days_out' => $daysOut,
            'period' => $this->calendar->holidayOn($context->night)?->name,
        ])];

        $skip = self::skipReason($context, $daysOut);
        if ($context->anchor === null || $skip !== null) {
            $skip ??= RuleCode::NO_MARKET_ANCHOR;
            $trace[] = self::entry($skip, ['sold' => $context->sold, 'units' => $context->units]);

            return new PaceDecision(...$common, kind: DecisionKind::Skipped, reasonCode: $skip, trace: $trace);
        }

        $aim = $this->aim($context, $context->anchor, $type, $daysOut, $today, $trace);
        $price = $this->price($context, $context->anchor, $aim, $daysOut, $trace);
        $common += [
            'reading' => $aim['reading'],
            'targetPercentile' => $aim['percentile'],
            'targetPublic' => $aim['targetPublic'],
            'targetSent' => $aim['targetSent'],
            'publicFactor' => $aim['factor'],
            'currentPercentile' => AnchorLookup::percentileOf($context->anchor, $context->currentPrice * $aim['factor']),
            'floor' => $price['floor'],
            'heldAtFloor' => $price['held'],
            'weakAnchor' => $aim['weak'],
        ];

        return $this->conclude($context, $common, $aim['targetSent'], $price, $trace);
    }

    /**
     * Why the engine leaves the night alone before looking at the market, or null.
     */
    private static function skipReason(NightContext $context, int $daysOut): ?string
    {
        if ($daysOut < 0) {
            return RuleCode::PAST_NIGHT;
        }

        return $context->isSoldOut() ? RuleCode::SOLD_OUT_PENDING_M2 : null;
    }

    /**
     * The pace status of the night and the target it gives.
     *
     * @param list<array{code: string, params: array<string, scalar|null>}> $trace
     *
     * @return array{reading: PaceReading, percentile: float, targetPublic: float, targetSent: float, factor: float, weak: bool}
     */
    private function aim(NightContext $context, MarketAnchor $anchor, NightType $type, int $daysOut, DateTimeImmutable $today, array &$trace): array
    {
        $current = $context->currentPrice;
        $reading = $this->meter->measure($type, $daysOut, $context->paceSold, $context->paceUnits);
        $trace[] = self::entry(RuleCode::PACE_STATUS, [
            'status' => $reading->status->value,
            'sold' => $context->paceSold,
            'units' => $context->paceUnits,
            'basis' => $context->usesGroupPace() ? 'group' : 'room',
            'sold_percent' => $reading->soldPercent,
            'expected_percent' => $reading->expectedPercent,
        ]);

        $factor = $this->factors->factor($context->room, PricePlan::PublicRate, $type);
        $percentile = $this->targets->forNight($context->property, $reading->status, $daysOut);
        $targetPublic = AnchorLookup::valueAt($anchor, $percentile);
        $targetSent = $targetPublic / $factor;
        $trace[] = self::entry(RuleCode::MARKET_TARGET, [
            'tier' => $context->property->tier,
            'ahead_near' => $this->targets->isAheadNear($reading->status, $daysOut),
            'percentile' => $percentile,
            'public' => $targetPublic,
            'factor' => $factor,
            'sent' => $targetSent,
            'anchor_origin' => $anchor->origin->value,
            'anchor_n' => $anchor->n,
            'anchor_captured_at' => $anchor->capturedAt === null ? null : Days::key($anchor->capturedAt),
        ]);

        $weak = $this->anchors->isWeak($anchor, $today);
        if ($weak) {
            $trace[] = self::entry(RuleCode::WEAK_ANCHOR, ['n' => $anchor->n, 'age_days' => $this->anchors->ageInDays($anchor, $today)]);
        }
        if ($reading->status === PaceStatus::Ahead && $targetSent < $current) {
            $trace[] = self::entry(RuleCode::AHEAD_NEVER_LOWERS, ['target' => $targetSent, 'current' => $current]);
            $targetSent = $current;
        }

        return ['reading' => $reading, 'percentile' => $percentile, 'targetPublic' => $targetPublic, 'targetSent' => $targetSent, 'factor' => $factor, 'weak' => $weak];
    }

    /**
     * One step towards the target, kept between the hard floor and the listing maximum and, for
     * tonight, never above the current price.
     *
     * @param array{targetSent: float, factor: float, weak: bool, ...} $aim
     * @param list<array{code: string, params: array<string, scalar|null>}> $trace
     *
     * @return array{new: float, held: bool, capped: bool, floor: HardFloor}
     */
    private function price(NightContext $context, MarketAnchor $anchor, array $aim, int $daysOut, array &$trace): array
    {
        $current = $context->currentPrice;
        $new = $this->steps->next($current, $aim['targetSent'], $daysOut, $aim['weak']);
        $trace[] = self::entry(RuleCode::STEP, [
            'from' => $current,
            'to' => $new,
            'strength' => $this->rules->stepStrength(),
            'max_percent' => $this->steps->maxPercent($daysOut, $aim['weak']),
        ]);

        $floor = $this->floors->hardFloor($context->room, $context->night);
        $held = $new < $floor->amount;
        if ($held) {
            $new = $floor->amount;
            $public = $floor->amount * $aim['factor'];
            $trace[] = self::entry(RuleCode::HELD_AT_FLOOR, [
                'floor' => $floor->amount,
                'label' => $floor->label,
                'public' => $public,
                'percentile' => AnchorLookup::percentileOf($anchor, $public),
            ]);
        }

        $top = $this->floors->highestFloor($context->room);
        $capped = $new >= $top - self::CENT;
        if ($capped) {
            $trace[] = self::entry(RuleCode::CAPPED_AT_LISTING_MAX, ['highest_floor' => $top, 'listing_max' => $context->room->max]);
        }
        $new = min($new, $top);

        if ($daysOut === 0 && $this->rules->neverRaiseToday() && $new > $current) {
            $trace[] = self::entry(RuleCode::TODAY_NEVER_RISES, ['wanted' => $new, 'current' => $current]);
            $new = $current;
        }

        return ['new' => $new, 'held' => $held, 'capped' => $capped, 'floor' => $floor];
    }

    /**
     * Turns the price reached into the decision: nothing within the tolerance, nothing while the
     * cadence holds the step, otherwise the floor and ceiling to write.
     *
     * @param array<string, mixed>                               $common fields of the decision known so far
     * @param array{new: float, capped: bool, ...}               $price
     * @param list<array{code: string, params: array<string, scalar|null>}> $trace
     */
    private function conclude(NightContext $context, array $common, float $targetSent, array $price, array $trace): PaceDecision
    {
        $current = $context->currentPrice;
        $direction = $this->steps->direction($current, $price['new']);
        $rounded = match ($direction) {
            StepDirection::Up => Num::ceilInt($price['new']),
            StepDirection::Down => Num::floorInt($price['new']),
            null => Num::roundInt($price['new']),
        };
        if ($price['capped']) {
            $rounded = Num::floorInt($price['new']);
        }

        $hold = null;
        if ($direction === null) {
            $hold = RuleCode::WITHIN_TOLERANCE;
            $trace[] = self::entry($hold, ['tolerance' => $this->rules->tolerance(), 'from' => $current, 'to' => $price['new']]);
        } elseif (($hold = $this->cadenceHold($context->lastStep, $direction)) !== null) {
            $trace[] = self::entry($hold, [
                'direction' => $direction->value,
                'last_step_at' => $context->lastStep?->recordedAt->format('Y-m-d\TH:i:s\Z'),
                'wanted' => (float) $rounded,
            ]);
        }
        if ($hold !== null) {
            return new PaceDecision(...$common, kind: DecisionKind::Hold, reasonCode: $hold, trace: $trace, proposedPrice: (float) $rounded, direction: $direction);
        }

        $ceiling = $this->ceilingFor($context, $direction, $rounded, $targetSent, $trace);
        [$min, $max] = $this->capAtListingMax($context->room, (float) $rounded, $ceiling);
        if (abs($min - $rounded) >= 0.5) {
            // The listing maximum moved the floor: the step is what will really be written.
            $direction = $this->steps->direction($current, $min);
        }
        $trace[] = self::entry(RuleCode::PACE_STEP, ['direction' => $direction?->value, 'from' => $current, 'min_price' => $min, 'max_price' => $max]);

        return new PaceDecision(...$common, kind: DecisionKind::Step, reasonCode: RuleCode::PACE_STEP, trace: $trace, proposedPrice: (float) $rounded, direction: $direction, minPrice: $min, maxPrice: $max);
    }

    /**
     * One step per night per day: a step in the same direction as the last one waits until that
     * one reached the sales channels and 24 hours passed. Returns the rule that holds the step,
     * or null when it may go.
     */
    private function cadenceHold(?LedgerStep $last, StepDirection $direction): ?string
    {
        if ($last === null || $last->direction !== $direction) {
            return null;
        }
        if (!$last->reachedChannel) {
            return RuleCode::CADENCE_PREVIOUS_STEP_NOT_PUSHED;
        }
        $elapsed = $this->clock->now()->getTimestamp() - $last->recordedAt->getTimestamp();

        return $elapsed < self::STEP_INTERVAL_SECONDS ? RuleCode::CADENCE_STEP_WITHIN_24H : null;
    }

    /**
     * The ceiling that goes with the new floor, or null to leave the ceiling alone.
     *
     * Going up, the ceiling sits at the target (never under the new floor) so the pricing
     * algorithm cannot jump past the step. Going down, the ceiling comes down with the floor when
     * the current price is held above it by the algorithm or by an older ceiling.
     *
     * @param list<array{code: string, params: array<string, scalar|null>}> $trace
     */
    private function ceilingFor(NightContext $context, StepDirection $direction, int $rounded, float $targetSent, array &$trace): ?float
    {
        $room = $context->room;
        if ($direction === StepDirection::Up) {
            $ceiling = (float) max($rounded, Num::floorInt(min($targetSent, $room->max)));
            $trace[] = self::entry(RuleCode::CEILING_AT_TARGET, ['ceiling' => $ceiling, 'target' => $targetSent]);

            return $ceiling;
        }

        $current = $context->currentPrice;
        $floorInForce = ($context->floorInForce ?? 0.0) > 0.0 ? $context->floorInForce : $room->min;
        $ceilingInForce = ($context->ceilingInForce ?? 0.0) > 0.0 ? $context->ceilingInForce : $room->max;
        if ($current > $rounded + 1 && ($current > $floorInForce + 1 || $ceilingInForce < $room->max - 1)) {
            $trace[] = self::entry(RuleCode::CEILING_FOLLOWS_FLOOR, ['ceiling' => (float) $rounded, 'floor_in_force' => $floorInForce, 'ceiling_in_force' => $ceilingInForce]);

            return (float) $rounded;
        }

        return null;
    }

    /**
     * Last pass: the floor as the pricing system will apply it (a whole percentage over the base,
     * rounded up) never passes the listing maximum, and with the floor at the top the ceiling
     * comes to the top with it.
     *
     * @return array{float, float|null} floor and ceiling
     */
    private function capAtListingMax(RoomType $room, float $min, ?float $max): array
    {
        $top = $this->floors->highestFloor($room);
        if (self::effectiveFloor($room, $min) > $top + self::CENT) {
            $min = (float) Num::floorInt($top);
        }
        $atTop = self::effectiveFloor($room, $min) >= $top - 0.01 * $room->base;
        if ($atTop && $max !== null && $max > $top + self::CENT) {
            $max = (float) max(Num::floorInt($top), $min);
        }

        return [$min, $max];
    }

    /**
     * The amount the pricing system applies for a floor: the base plus a whole percentage,
     * rounded up.
     */
    private static function effectiveFloor(RoomType $room, float $amount): float
    {
        return Num::percentToAmount((float) Num::floorToPercent($amount, $room->base), $room->base, 2);
    }

    /**
     * @param array<string, scalar|null> $params
     *
     * @return array{code: string, params: array<string, scalar|null>}
     */
    private static function entry(string $code, array $params): array
    {
        return ['code' => $code, 'params' => $params];
    }
}
