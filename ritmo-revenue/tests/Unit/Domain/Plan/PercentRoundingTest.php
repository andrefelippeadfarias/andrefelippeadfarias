<?php

declare(strict_types=1);

namespace Ritmo\Tests\Unit\Domain\Plan;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Ritmo\Domain\Model\RoomType;
use Ritmo\Domain\Plan\NightState;
use Ritmo\Domain\Plan\PercentItem;
use Ritmo\Domain\Plan\PercentRounding;
use Ritmo\Domain\Plan\Plan;
use Ritmo\Domain\Plan\PlanItem;
use Ritmo\Domain\Plan\Validator;
use Ritmo\Domain\Support\Days;
use Ritmo\Domain\Support\FixedClock;
use Ritmo\Tests\Unit\Domain\EngineSample;

/**
 * The Deluxe room has a base of 1000: an amount of 905 is -9.5% of it.
 */
final class PercentRoundingTest extends TestCase
{
    private static function item(?float $min, ?float $max = null, ?NightState $before = null, string $night = '2027-03-10', ?int $minStay = null): PlanItem
    {
        return new PlanItem('Deluxe', Days::date($night), $min, $max, $minStay, 'pace_step', [], $before);
    }

    public function testAFloorRoundsUpAndACeilingRoundsDown(): void
    {
        $rounding = new PercentRounding();
        $room = EngineSample::deluxe();

        self::assertSame(-9, $rounding->convert(self::item(905.0), $room)->minPercent, 'a floor of -9.5% is sent as -9%');
        self::assertSame(-10, $rounding->convert(self::item(null, 905.0), $room)->maxPercent, 'a ceiling of -9.5% is sent as -10%');
        self::assertSame(-10, $rounding->convert(self::item(900.0), $room)->minPercent, 'a whole percentage stays');
        self::assertSame(23, $rounding->convert(self::item(null, 1234.0), $room)->maxPercent);
        self::assertSame(24, $rounding->convert(self::item(1234.0), $room)->minPercent);
    }

    public function testTheCeilingIsNeverLeftUnderTheFloor(): void
    {
        $percent = (new PercentRounding())->convert(self::item(905.0, 905.0), EngineSample::deluxe());

        self::assertSame([-9, -9], [$percent->minPercent, $percent->maxPercent], 'a locked price: the ceiling follows the floor up');
        $apart = (new PercentRounding())->convert(self::item(905.0, 1200.0), EngineSample::deluxe());
        self::assertSame([-9, 20], [$apart->minPercent, $apart->maxPercent], 'a ceiling over the floor is left alone');
    }

    public function testFieldsTheItemDoesNotSetStayNull(): void
    {
        $rounding = new PercentRounding();

        $floorOnly = $rounding->convert(self::item(905.0), EngineSample::deluxe());
        self::assertNull($floorOnly->maxPercent);
        self::assertNull($floorOnly->maxAmount());
        $stayOnly = $rounding->convert(self::item(null, null, null, '2027-03-10', 2), EngineSample::deluxe());
        self::assertSame([null, null], [$stayOnly->minPercent, $stayOnly->maxPercent]);
        self::assertNull($stayOnly->minAmount());
    }

    public function testAppliedAmounts(): void
    {
        $room = new RoomType('inn', 'Odd', 'Odd room', 1, '', '', 1173.0, 600.0, 5000.0, 700.0);
        $percent = (new PercentRounding())->convert(new PlanItem('Odd', Days::date('2027-03-10'), 900.0, 1000.0), $room);

        self::assertSame([-23, -15], [$percent->minPercent, $percent->maxPercent]);
        self::assertSame(903.0, $percent->minAmount(), 'the whole amount the pricing system computes');
        self::assertSame(903.21, $percent->minAmount(2), 'the exact amount of -23%');
        self::assertSame(997.0, $percent->maxAmount());
        self::assertSame(997.05, $percent->maxAmount(2));
        self::assertGreaterThanOrEqual(900.0, $percent->minAmount(2), 'the floor applied is never under the floor asked');
        self::assertLessThanOrEqual(1000.0, $percent->maxAmount(2), 'the ceiling applied is never over the ceiling asked');
    }

    public function testRejectsAnItemOfAnotherRoom(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new PercentRounding())->convert(self::item(905.0), EngineSample::standard());
    }

    public function testSameAsInForceNeedsEverySetValueToBeTheSamePercentage(): void
    {
        $same = static fn (PlanItem $item): bool => (new PercentRounding())->convert($item, EngineSample::deluxe())->sameAsInForce();

        self::assertTrue($same(self::item(900.0, null, new NightState(880.0, 870.0, null, null, -10.0))), 'the floor asked is the -10% already in force');
        self::assertTrue($same(self::item(895.5, null, new NightState(880.0, 870.0, null, null, -10.0))), 'so is one that only differs by the rounding');
        self::assertFalse($same(self::item(900.0, null, new NightState(880.0, 870.0, null, null, -11.0))));
        self::assertFalse($same(self::item(900.0, null, new NightState(880.0, 900.0))), 'the percentage in force is not known');
        self::assertFalse($same(self::item(900.0)), 'nothing is known');
        self::assertTrue($same(self::item(900.0, 950.0, new NightState(null, null, null, null, -10.0, -5.0))));
        self::assertFalse($same(self::item(900.0, 950.0, new NightState(null, null, null, null, -10.0, -4.0))), 'the ceiling changes');
        self::assertFalse($same(self::item(900.0, null, new NightState(null, null, null, 1, -10.0), '2027-03-10', 2)), 'the stay changes');
        self::assertTrue($same(self::item(900.0, null, new NightState(null, null, null, 2, -10.0), '2027-03-10', 2)));
    }

    public function testApplyDropsTheItemsThatOnlyChangeByRounding(): void
    {
        $clock = new FixedClock('2027-03-03T12:00:00+00:00');
        // The calendar still shows old amounts, so the validator sees a change; the override in force says otherwise.
        $sameAsInForce = self::item(900.0, null, new NightState(1000.0, 870.0, null, null, -10.0), '2027-03-10');
        $realChange = self::item(900.0, null, new NightState(1000.0, 870.0, null, null, -13.0), '2027-03-11');
        $unknown = self::item(900.0, null, new NightState(1000.0, 870.0), '2027-03-15');
        $plan = new Plan('inn', $clock->today('UTC'), $clock->now(), [$sameAsInForce, $realChange, $unknown]);
        $validated = (new Validator(EngineSample::rules(), $clock))->validate($plan, EngineSample::property(), [EngineSample::deluxe()])->plan;
        self::assertNotNull($validated);

        $percent = (new PercentRounding())->apply($validated, [EngineSample::deluxe(), EngineSample::standard()]);

        self::assertSame($validated, $percent->plan);
        self::assertSame([$realChange, $unknown], array_map(static fn (PercentItem $item): PlanItem => $item->item, $percent->items));
        self::assertSame([$sameAsInForce], array_map(static fn (PercentItem $item): PlanItem => $item->item, $percent->dropped));
        self::assertSame(-10, $percent->items[0]->minPercent);

        $this->expectException(InvalidArgumentException::class);
        (new PercentRounding())->apply($validated, [EngineSample::standard()]);
    }

    public function testApplyOnlyConvertsWithTheBasesThePlanWasValidatedWith(): void
    {
        $clock = new FixedClock('2027-03-03T12:00:00+00:00');
        $plan = new Plan('inn', $clock->today('UTC'), $clock->now(), [self::item(900.0, null, new NightState(1000.0, 870.0), '2027-03-10')]);
        $validated = (new Validator(EngineSample::rules(), $clock))->validate($plan, EngineSample::property(), [EngineSample::deluxe()])->plan;
        self::assertNotNull($validated);
        self::assertSame(['Deluxe' => 1000.0], $validated->bases);

        // The same room re-read with a base of 3000: -10% there would be 2700, not the 900 validated.
        $reread = new RoomType('inn', 'Deluxe', 'Deluxe room', 4, 'pms-1', 'pricing-1', 3000.0, 600.0, 3000.0, 700.0, ['tub']);
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('validated with 1000.00');
        (new PercentRounding())->apply($validated, [$reread]);
    }
}
