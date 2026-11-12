<?php

declare(strict_types=1);

namespace Ritmo\Tests\Unit\Domain\Plan;

use PHPUnit\Framework\TestCase;
use Ritmo\Domain\Plan\CalendarNight;
use Ritmo\Domain\Plan\FreshCalendar;
use Ritmo\Domain\Plan\NightState;
use Ritmo\Domain\Plan\Plan;
use Ritmo\Domain\Plan\PlanIssue;
use Ritmo\Domain\Plan\PlanItem;
use Ritmo\Domain\Plan\ValidationContext;
use Ritmo\Domain\Plan\Validator;
use Ritmo\Domain\Support\Days;
use Ritmo\Domain\Support\FixedClock;
use Ritmo\Tests\Unit\Domain\EngineSample;

/**
 * The checks that read what is in force for the night when the item's record and the fresh
 * calendar disagree, and the floor that only writes again the floor in force.
 */
final class FloorInForceChecksTest extends TestCase
{
    private const NOW = '2027-03-03T12:00:00+00:00';
    private const TODAY = '2027-03-03';

    /**
     * @return list<string>
     */
    private static function errors(PlanItem $item, ?CalendarNight $fresh = null): array
    {
        $clock = new FixedClock(self::NOW);
        $calendar = $fresh === null ? null : new FreshCalendar([$item->key() => $fresh], ['Deluxe' => $clock->now()]);
        $codes = (new Validator(EngineSample::rules(), $clock))
            ->validate(new Plan('inn', Days::date(self::TODAY), $clock->now(), [$item]), EngineSample::property(), [EngineSample::deluxe()], new ValidationContext($calendar))
            ->errorCodes();
        sort($codes);

        return $codes;
    }

    private static function item(string $night, ?float $min, ?float $max, NightState $before): PlanItem
    {
        return new PlanItem('Deluxe', Days::date($night), $min, $max, null, 'pace_step', [], $before);
    }

    public function testTonightIsComparedWithTheLowestPriceKnown(): void
    {
        // The item's record says 1200, the fresh calendar 900: a floor of 1000 would raise tonight.
        $item = self::item(self::TODAY, 1000.0, null, new NightState(1200.0, 600.0, 3000.0));

        self::assertSame([PlanIssue::RAISE_TODAY], self::errors($item, new CalendarNight(900.0, 600.0, 1, 4)));
        self::assertSame([], self::errors($item, new CalendarNight(1250.0, 600.0, 1, 4)), 'both prices above the floor');
        self::assertSame([], self::errors($item), 'without a fresh calendar the record is what is known');
    }

    public function testAFloorThatWritesAgainTheFloorInForceRaisesNothingAndChangesNothing(): void
    {
        // Base 1000: the rule in force is +4% (1040); the calendar shows the price and floor as 1039.
        $inForce = new NightState(1039.0, 1039.0, 3000.0, null, 4.0);
        $rewrite = self::item(self::TODAY, 1039.5, null, $inForce);

        self::assertSame([PlanIssue::NO_EFFECT], self::errors($rewrite, new CalendarNight(1039.0, 1039.0, 4, 4)), 'no effect, and no raise of tonight');
        self::assertSame([PlanIssue::NO_EFFECT, PlanIssue::RAISE_TODAY], self::errors($rewrite, new CalendarNight(1039.0, 1045.0, 4, 4)), 'the fresh floor is another percentage: tonight would rise');
        self::assertSame([PlanIssue::NO_EFFECT, PlanIssue::RAISE_TODAY], self::errors(self::item(self::TODAY, 1039.5, null, new NightState(1039.0, 1039.0, 3000.0))), 'no rule in force: the rounding raises tonight');
    }

    public function testACeilingAloneIsComparedWithTheHighestFloorInForce(): void
    {
        $item = self::item('2027-03-10', null, 800.0, new NightState(1000.0, 600.0, 3000.0));

        self::assertSame([PlanIssue::CEILING_BELOW_FLOOR], self::errors($item, new CalendarNight(1000.0, 900.0, 1, 4)), 'the fresh floor of 900 is over the ceiling');
        self::assertSame([], self::errors($item, new CalendarNight(1000.0, 500.0, 1, 4)));
    }
}
