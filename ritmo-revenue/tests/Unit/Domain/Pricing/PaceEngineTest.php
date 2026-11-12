<?php

declare(strict_types=1);

namespace Ritmo\Tests\Unit\Domain\Pricing;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Ritmo\Domain\Model\AnchorOrigin;
use Ritmo\Domain\Model\MarketAnchor;
use Ritmo\Domain\Model\NightType;
use Ritmo\Domain\Model\PaceStatus;
use Ritmo\Domain\Model\RoomType;
use Ritmo\Domain\Pricing\DecisionKind;
use Ritmo\Domain\Pricing\Floors;
use Ritmo\Domain\Pricing\LedgerStep;
use Ritmo\Domain\Pricing\NightContext;
use Ritmo\Domain\Pricing\PaceDecision;
use Ritmo\Domain\Pricing\PaceEngine;
use Ritmo\Domain\Pricing\RuleCode;
use Ritmo\Domain\Pricing\StepDirection;
use Ritmo\Domain\Support\Days;
use Ritmo\Domain\Support\FixedClock;
use Ritmo\Tests\Unit\Domain\EngineSample;

/**
 * The engine on the sample inn (see EngineSample). "Now" is Wednesday 3 March 2027, noon UTC.
 *
 * The usual night is the Deluxe room on Wednesday 10 March, 7 days away: a weekday expects 35%
 * sold, the hard floor is 800 (owner floor) and the market anchor is 400 / 600 / 800 / 1000 /
 * 1200 in public prices, which are 80% of the sent price.
 */
final class PaceEngineTest extends TestCase
{
    private const NOW = '2027-03-03T12:00:00+00:00';
    private const NIGHT = '2027-03-10';

    /**
     * @param array<string, mixed> $with overrides of the usual night
     */
    private static function decide(array $with = []): PaceDecision
    {
        $night = $with['night'] ?? self::NIGHT;
        $room = $with['room'] ?? EngineSample::deluxe();
        $context = new NightContext(
            $with['property'] ?? EngineSample::property(),
            $room,
            Days::date($night),
            $with['price'] ?? 1000.0,
            $with['sold'] ?? 0,
            $with['units'] ?? $room->units,
            array_key_exists('anchor', $with) ? $with['anchor'] : EngineSample::anchor($night),
            $with['floorInForce'] ?? null,
            $with['ceilingInForce'] ?? null,
            $with['lastStep'] ?? null,
            $with['paceSold'] ?? null,
            $with['paceUnits'] ?? null,
        );

        return (new PaceEngine($with['rules'] ?? EngineSample::rules(), new FixedClock(self::NOW)))->decide($context);
    }

    public function testANightVeryBehindStepsDownTowardsItsTarget(): void
    {
        $decision = self::decide();

        self::assertSame(DecisionKind::Step, $decision->kind);
        self::assertSame(RuleCode::PACE_STEP, $decision->reasonCode);
        self::assertSame('Deluxe', $decision->roomCode);
        self::assertSame(self::NIGHT, Days::key($decision->night));
        self::assertSame(7, $decision->daysOut);
        self::assertSame(NightType::Weekday, $decision->nightType);
        self::assertSame(PaceStatus::VeryBehind, $decision->reading?->status, '0% sold against 35% expected');
        self::assertSame(30.0, $decision->targetPercentile);
        self::assertEqualsWithDelta(440.0, $decision->targetPublic, 1e-9, 'the 30th percentile, between 400 and 600');
        self::assertEqualsWithDelta(550.0, $decision->targetSent, 1e-9, '440 / 0.8');
        self::assertSame(0.8, $decision->publicFactor);
        self::assertSame(75.0, $decision->currentPercentile, '1000 sent = 800 public = the p75');
        self::assertSame(StepDirection::Down, $decision->direction);
        self::assertSame(850.0, $decision->proposedPrice, 'the full distance is far: the step stops at -15%');
        self::assertSame(850.0, $decision->minPrice);
        self::assertSame(850.0, $decision->maxPrice, 'the price was above its floor: the ceiling comes down too');
        self::assertSame(800.0, $decision->floor?->amount);
        self::assertFalse($decision->heldAtFloor);
        self::assertFalse($decision->weakAnchor);
        self::assertTrue($decision->applied(RuleCode::CEILING_FOLLOWS_FLOOR));
        self::assertFalse($decision->applied(RuleCode::WEAK_ANCHOR));
    }

    public function testTheStepStopsAtTheHardFloor(): void
    {
        $decision = self::decide(['price' => 900.0]);

        self::assertTrue($decision->isStep());
        self::assertTrue($decision->heldAtFloor, '-15% of 900 is 765, under the floor of 800');
        self::assertSame(800.0, $decision->minPrice);
        self::assertSame(Floors::OWNER, $decision->floor?->label);
        self::assertSame(
            ['floor' => 800.0, 'label' => Floors::OWNER, 'public' => 640.0, 'percentile' => 55.0],
            $decision->traceParams(RuleCode::HELD_AT_FLOOR)
        );
    }

    public function testANightAtItsFloorAlreadyIsLeftAlone(): void
    {
        $decision = self::decide(['price' => 800.0]);

        self::assertSame(DecisionKind::Hold, $decision->kind);
        self::assertSame(RuleCode::WITHIN_TOLERANCE, $decision->reasonCode);
        self::assertTrue($decision->heldAtFloor);
        self::assertNull($decision->direction);
        self::assertNull($decision->minPrice);
        self::assertSame(800.0, $decision->proposedPrice);
    }

    public function testGoingDownTheCeilingFollowsOnlyWhenSomethingHoldsThePriceUp(): void
    {
        self::assertNull(self::decide(['floorInForce' => 1000.0])->maxPrice, 'the price sits on its own floor: lowering the floor is enough');
        self::assertSame(850.0, self::decide(['floorInForce' => 1000.0])->minPrice);
        self::assertSame(850.0, self::decide(['floorInForce' => 1000.0, 'ceilingInForce' => 1000.0])->maxPrice, 'an old ceiling would hold the price');
        self::assertNull(self::decide(['floorInForce' => 1000.0, 'ceilingInForce' => 3000.0])->maxPrice, 'the listing maximum is no ceiling');
        self::assertSame(850.0, self::decide(['floorInForce' => 0.0])->maxPrice, 'a floor of zero is an unknown floor: the listing minimum is assumed');
    }

    public function testANightAheadOfItsCurveNeverGoesDown(): void
    {
        $decision = self::decide(['sold' => 2]);

        self::assertSame(PaceStatus::Ahead, $decision->reading?->status, '50% sold against 35% expected');
        self::assertSame(70.0, $decision->targetPercentile, 'ahead and 7 days away');
        self::assertEqualsWithDelta(760.0, $decision->targetPublic, 1e-9);
        self::assertSame(1000.0, $decision->targetSent, 'the target of 950 is under the current price: the price stays');
        self::assertSame(DecisionKind::Hold, $decision->kind);
        self::assertSame(RuleCode::WITHIN_TOLERANCE, $decision->reasonCode);
        self::assertTrue($decision->applied(RuleCode::AHEAD_NEVER_LOWERS));
        self::assertFalse(self::decide()->applied(RuleCode::AHEAD_NEVER_LOWERS));
    }

    public function testGoingUpTheCeilingSitsAtTheTarget(): void
    {
        $decision = self::decide(['sold' => 2, 'price' => 800.0]);

        self::assertSame(StepDirection::Up, $decision->direction);
        self::assertSame(875.0, $decision->minPrice, 'half of the way from 800 to 950');
        self::assertSame(950.0, $decision->maxPrice);
        self::assertTrue($decision->applied(RuleCode::CEILING_AT_TARGET));
        self::assertFalse($decision->heldAtFloor);

        self::assertSame(876.0, self::decide(['sold' => 2, 'price' => 801.0])->minPrice, 'a rise is rounded up: 875.5 becomes 876');
    }

    public function testAFallIsRoundedDown(): void
    {
        self::assertSame(850.0, self::decide(['price' => 1001.0])->minPrice, '850.85 becomes 850');
    }

    public function testThePriceOfTonightNeverGoesUp(): void
    {
        $tonight = self::decide(['night' => '2027-03-03', 'sold' => 2, 'price' => 500.0]);

        self::assertSame(0, $tonight->daysOut);
        self::assertSame(DecisionKind::Hold, $tonight->kind, 'the floor of 800 would raise it: tonight stays at 500');
        self::assertTrue($tonight->applied(RuleCode::TODAY_NEVER_RISES));
        self::assertTrue($tonight->heldAtFloor);
        self::assertSame(500.0, $tonight->proposedPrice);

        $tomorrow = self::decide(['night' => '2027-03-04', 'sold' => 2, 'price' => 500.0]);
        self::assertTrue($tomorrow->isStep(), 'tomorrow the floor does raise it');
        self::assertSame(800.0, $tomorrow->minPrice);
        self::assertFalse($tomorrow->applied(RuleCode::TODAY_NEVER_RISES));
    }

    public function testThePriceOfTonightMayGoDown(): void
    {
        $decision = self::decide(['night' => '2027-03-03', 'price' => 1000.0]);

        self::assertSame(PaceStatus::VeryBehind, $decision->reading?->status, 'nothing sold on the day');
        self::assertSame(StepDirection::Down, $decision->direction);
        self::assertSame(850.0, $decision->minPrice);
    }

    public function testNothingSoldCloseToTheNightIsVeryBehindWhateverTheCurve(): void
    {
        // 29 days away a weekday expects about 11%: with nothing sold the gap alone is "on pace".
        self::assertSame(PaceStatus::OnPace, self::decide(['night' => '2027-04-01'])->reading?->status);
        self::assertSame(PaceStatus::VeryBehind, self::decide(['night' => '2027-03-08'])->reading?->status, '5 days away');
    }

    public function testASoldOutNightIsLeftForALaterMilestone(): void
    {
        foreach ([4, 5] as $sold) {
            $decision = self::decide(['sold' => $sold]);

            self::assertSame(DecisionKind::Skipped, $decision->kind);
            self::assertSame(RuleCode::SOLD_OUT_PENDING_M2, $decision->reasonCode);
            self::assertNull($decision->reading);
            self::assertNull($decision->minPrice);
            self::assertSame([RuleCode::NIGHT, RuleCode::SOLD_OUT_PENDING_M2], array_column($decision->trace, 'code'));
        }
        self::assertSame(RuleCode::SOLD_OUT_PENDING_M2, self::decide(['sold' => 4, 'anchor' => null])->reasonCode, 'sold out comes before the missing anchor');
    }

    public function testANightWithoutAnchorOrInThePastIsSkipped(): void
    {
        $noAnchor = self::decide(['anchor' => null]);
        self::assertSame(DecisionKind::Skipped, $noAnchor->kind);
        self::assertSame(RuleCode::NO_MARKET_ANCHOR, $noAnchor->reasonCode);
        self::assertNull($noAnchor->targetSent);

        $past = self::decide(['night' => '2027-03-02']);
        self::assertSame(DecisionKind::Skipped, $past->kind);
        self::assertSame(RuleCode::PAST_NIGHT, $past->reasonCode);
        self::assertSame(-1, $past->daysOut);
    }

    public function testAWeakAnchorHalvesTheStep(): void
    {
        $small = self::decide(['anchor' => EngineSample::anchor(self::NIGHT, 3)]);
        self::assertTrue($small->weakAnchor);
        self::assertSame(925.0, $small->minPrice, '-7.5% instead of -15%');
        self::assertSame(['n' => 3, 'age_days' => 2], $small->traceParams(RuleCode::WEAK_ANCHOR));

        $old = self::decide(['anchor' => EngineSample::anchor(self::NIGHT, 12, '2027-02-16')]);
        self::assertTrue($old->weakAnchor, '15 days old');
        self::assertSame(925.0, $old->minPrice);

        self::assertSame(850.0, self::decide(['anchor' => EngineSample::anchor(self::NIGHT, 5, '2027-02-17')])->minPrice, '5 samples and 14 days are still enough');
    }

    public function testOneStepPerNightPerDayAndDirection(): void
    {
        $step = static fn (StepDirection $direction, string $at, bool $reached): LedgerStep => new LedgerStep($direction, new DateTimeImmutable($at), $reached);

        $notPushed = self::decide(['lastStep' => $step(StepDirection::Down, '2027-03-01T10:00:00+00:00', false)]);
        self::assertSame(DecisionKind::Hold, $notPushed->kind);
        self::assertSame(RuleCode::CADENCE_PREVIOUS_STEP_NOT_PUSHED, $notPushed->reasonCode, 'however old, a step that never reached the channels holds the next');
        self::assertSame(StepDirection::Down, $notPushed->direction);
        self::assertSame(850.0, $notPushed->proposedPrice);
        self::assertNull($notPushed->minPrice);

        $tooSoon = self::decide(['lastStep' => $step(StepDirection::Down, '2027-03-02T12:00:01+00:00', true)]);
        self::assertSame(RuleCode::CADENCE_STEP_WITHIN_24H, $tooSoon->reasonCode, '23:59:59 ago');
        self::assertSame(
            ['direction' => 'down', 'last_step_at' => '2027-03-02T12:00:01Z', 'wanted' => 850.0],
            $tooSoon->traceParams(RuleCode::CADENCE_STEP_WITHIN_24H)
        );

        self::assertTrue(self::decide(['lastStep' => $step(StepDirection::Down, '2027-03-02T12:00:00+00:00', true)])->isStep(), 'exactly 24 hours ago');
        self::assertTrue(self::decide(['lastStep' => $step(StepDirection::Up, '2027-03-03T11:00:00+00:00', false)])->isStep(), 'a step the other way does not hold this one');
    }

    public function testThePaceMayBeMeasuredOverAGroupOfRooms(): void
    {
        $decision = self::decide(['paceSold' => 8, 'paceUnits' => 10]);

        self::assertSame(PaceStatus::Ahead, $decision->reading?->status, '80% of the group against 35%');
        self::assertSame(80.0, $decision->reading->soldPercent);
        self::assertSame('group', $decision->traceParams(RuleCode::PACE_STATUS)['basis'] ?? null);
        self::assertSame('room', self::decide()->traceParams(RuleCode::PACE_STATUS)['basis'] ?? null);
    }

    public function testTheTierOfThePropertyMovesTheTarget(): void
    {
        self::assertSame(40.0, self::decide(['property' => EngineSample::property('premium')])->targetPercentile);
    }

    public function testAFloorOverTheListingMaximumStopsAtTheMaximum(): void
    {
        // The Standard room on a night of the fair: the holiday floor is 900 / 0.4 = 2250, over its maximum of 1500.
        $decision = self::decide(['room' => EngineSample::standard(), 'night' => '2027-03-18']);

        self::assertSame(NightType::Holiday, $decision->nightType);
        self::assertSame(2250.0, $decision->floor?->amount);
        self::assertTrue($decision->heldAtFloor);
        self::assertTrue($decision->applied(RuleCode::CAPPED_AT_LISTING_MAX));
        self::assertSame(1500.0, $decision->minPrice);
        self::assertSame(1500.0, $decision->maxPrice);
        self::assertSame('Spring fair', $decision->traceParams(RuleCode::NIGHT)['period'] ?? null);
    }

    public function testTheLastPassKeepsTheAppliedFloorUnderTheListingMaximum(): void
    {
        // Base 1173 and maximum 5000: the highest floor is +326% = 4996.98. A rise to 4996.5 is
        // rounded up to 4997, which the pricing system would apply as +327%, over the maximum.
        $room = new RoomType('inn', 'Odd', 'Odd room', 4, '', '', 1173.0, 900.0, 5000.0, 800.0);
        $anchor = new MarketAnchor(Days::date(self::NIGHT), 4000.0, 4100.0, 4200.0, 4300.0, 4400.0, 12, Days::date('2027-03-01'), AnchorOrigin::Measured);
        // Ahead and close: the 70th percentile is 4180 public = 5225 sent; half of the way from 4768 is 4996.5.
        $decision = self::decide(['room' => $room, 'anchor' => $anchor, 'sold' => 2, 'price' => 4768.0]);

        self::assertSame(StepDirection::Up, $decision->direction);
        self::assertSame(4997.0, $decision->proposedPrice);
        self::assertSame(4996.0, $decision->minPrice, 'lowered so that the percentage applied stays at +326%');
        self::assertSame(4996.0, $decision->maxPrice, 'with the floor at the top, the ceiling of 5000 comes to the top with it');
    }

    public function testAPriceOfZeroGoesToTheFloor(): void
    {
        $decision = self::decide(['price' => 0.0]);

        self::assertSame(StepDirection::Up, $decision->direction);
        self::assertSame(800.0, $decision->minPrice);
        self::assertSame(800.0, $decision->maxPrice, 'the target of 550 is under the floor: the ceiling is the floor');
        self::assertSame(0.0, $decision->currentPercentile);
    }

    public function testTheTraceIsCodesAndPlainParameters(): void
    {
        // A weak anchor: -7.5% of 850 is 786.25, under the floor of 800.
        $decision = self::decide(['price' => 850.0, 'anchor' => EngineSample::anchor(self::NIGHT, 3)]);

        self::assertSame(
            [RuleCode::NIGHT, RuleCode::PACE_STATUS, RuleCode::MARKET_TARGET, RuleCode::WEAK_ANCHOR, RuleCode::STEP, RuleCode::HELD_AT_FLOOR, RuleCode::CEILING_FOLLOWS_FLOOR, RuleCode::PACE_STEP],
            array_column($decision->trace, 'code')
        );
        foreach ($decision->trace as $entry) {
            self::assertSame(['code', 'params'], array_keys($entry));
            foreach ($entry['params'] as $name => $value) {
                self::assertMatchesRegularExpression('/^[a-z][a-z0-9_]*$/', (string) $name);
                self::assertTrue($value === null || is_scalar($value), "$name must be plain data");
            }
        }
        self::assertSame(['night_type' => 'weekday', 'days_out' => 7, 'period' => null], $decision->traceParams(RuleCode::NIGHT));
        self::assertNull($decision->traceParams(RuleCode::TODAY_NEVER_RISES));
    }

    public function testToArrayIsPlainDataForStorage(): void
    {
        $data = self::decide()->toArray();

        self::assertSame('Deluxe', $data['room']);
        self::assertSame(self::NIGHT, $data['night']);
        self::assertSame('step', $data['kind']);
        self::assertSame('pace_step', $data['reason_code']);
        self::assertSame('very_behind', $data['pace_status']);
        self::assertSame('down', $data['direction']);
        self::assertSame(850.0, $data['min_price']);
        self::assertSame('owner_floor', $data['floor_label']);
        self::assertSame('measured', $data['anchor_origin']);
        self::assertSame(json_decode(json_encode($data, JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR), true), $data, 'survives JSON unchanged');

        $skipped = self::decide(['anchor' => null])->toArray();
        self::assertSame('skipped', $skipped['kind']);
        self::assertNull($skipped['pace_status']);
        self::assertNull($skipped['anchor_origin']);
    }
}
