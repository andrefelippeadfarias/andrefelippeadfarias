<?php

declare(strict_types=1);

namespace Ritmo\Tests\Unit\Domain\Calendar\Stay;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Ritmo\Domain\Calendar\Stay\PlannedStay;
use Ritmo\Domain\Support\Days;

/**
 * 1 March 2027 is a Monday; "Spring fair" (18-21 March) is a holiday with a stay of 2 and
 * "Local week" (5-7 April) one without a stay.
 */
final class StayRulesTest extends TestCase
{
    public function testTheArrivalWeekdayTableCanDifferByPropertyAndRoom(): void
    {
        $rules = StaySample::rules(['arrival_rule' => StaySample::ARRIVAL + [
            'properties' => ['annex' => ['4' => 3], 'inn' => ['0' => 3]],
            'rooms' => ['Deluxe' => ['0' => 1]],
        ]]);

        self::assertSame(1, $rules->arrivalWeekdayStay('Deluxe', Days::date('2027-03-08')), 'the room wins over its property');
        self::assertSame(3, $rules->arrivalWeekdayStay('Standard', Days::date('2027-03-08')), 'the property wins over the site');
        self::assertSame(3, $rules->arrivalWeekdayStay('Cottage', Days::date('2027-03-12')));
        self::assertSame(2, $rules->arrivalWeekdayStay('Standard', Days::date('2027-03-12')));
        self::assertNull($rules->arrivalWeekdayStay('Standard', Days::date('2026-12-28')), 'before the rule starts');
        self::assertNull($rules->requiredStay('Standard', Days::date('2026-12-29')));
        $this->expectException(InvalidArgumentException::class);
        $rules->arrivalWeekdayStay('Unknown', Days::date('2027-03-08'));
    }

    public function testRequiredStayFollowsPackageHolidaySeasonAndClosedDay(): void
    {
        $rules = StaySample::rules([
            'arrival_rule' => StaySample::ARRIVAL,
            'closed_days' => [StaySample::SATURDAY],
            'holiday_min_stay' => 2,
            'packages' => [['name' => 'Fair package', 'nights' => [['date' => '2027-03-19', 'min_stay' => 4], ['date' => '2027-03-20', 'max_stay' => 3]]]],
            'seasons' => [
                ['name' => 'Early March', 'start' => '2027-03-01', 'end' => '2027-03-14', 'min_stay' => 3],
                ['name' => 'Fair fortnight', 'start' => '2027-03-15', 'end' => '2027-03-25', 'min_stay' => 3],
            ],
        ]);

        self::assertSame(4, $rules->requiredStay('Deluxe', Days::date('2027-03-19')), 'package over holiday');
        self::assertSame(2, $rules->requiredStay('Deluxe', Days::date('2027-03-20')), 'a package night without a minimum keeps the holiday stay');
        self::assertSame(2, $rules->requiredStay('Deluxe', Days::date('2027-03-18')), 'the holiday own stay');
        self::assertSame(2, $rules->requiredStay('Deluxe', Days::date('2027-04-05')), 'holiday without stay nor season: the holiday default');
        self::assertSame(3, $rules->requiredStay('Deluxe', Days::date('2027-03-02')), 'a season outside holidays raises the arrival stay');
        self::assertSame(1, $rules->requiredStay('Deluxe', Days::date('2027-03-16')), 'a season that overlaps a holiday does not raise');
        self::assertSame(3, $rules->requiredStay('Deluxe', Days::date('2027-03-13')), 'closed day raised to its season');

        $withSeasonOnHoliday = StaySample::rules(['seasons' => [['name' => 'April', 'start' => '2027-04-01', 'end' => '2027-04-10', 'min_stay' => 3]]]);
        self::assertSame(3, $withSeasonOnHoliday->requiredStay('Deluxe', Days::date('2027-04-05')), 'a holiday without stay takes its season');
        self::assertNull($withSeasonOnHoliday->requiredStay('Deluxe', Days::date('2027-04-12')), 'nothing requires a stay');

        $closedOnly = StaySample::rules(['closed_days' => [StaySample::SATURDAY]]);
        self::assertSame(2, $closedOnly->requiredStay('Deluxe', Days::date('2027-03-13')), 'without the arrival rule the closed day keeps its own stay');
        self::assertNull($closedOnly->requiredStay('Deluxe', Days::date('2027-03-12')));
    }

    public function testThePlannedStayGoesUpToTheTargetAndDownOnlyToOneNight(): void
    {
        $rules = StaySample::rules(['arrival_rule' => StaySample::ARRIVAL]);
        $thursday = Days::date('2027-03-04');
        $wednesday = Days::date('2027-03-03');

        self::assertEquals(new PlannedStay(1), $rules->plannedStay('Deluxe', $thursday, 3, true, false));
        self::assertEquals(new PlannedStay(1), $rules->plannedStay('Deluxe', $thursday, null, true, false));
        self::assertEquals(new PlannedStay(2), $rules->plannedStay('Deluxe', $wednesday, 1, true, false));
        self::assertEquals(new PlannedStay(3), $rules->plannedStay('Deluxe', $wednesday, 3, true, false), 'above a target of two stays');
        self::assertEquals(new PlannedStay(2), $rules->plannedStay('Deluxe', $wednesday, 2, true, true), 'orphan night off');
    }

    public function testAFreeNightBeforeASoldOutNightIsAnOrphanOutsidePackagesAndOnlyOnHolidaysWhenAllowed(): void
    {
        $stay = ['arrival_rule' => StaySample::ARRIVAL, 'orphan' => ['next_night' => true],
                 'packages' => [['name' => 'P', 'nights' => [['date' => '2027-03-10', 'min_stay' => 3]]]]];
        $rules = StaySample::rules($stay);

        self::assertEquals(new PlannedStay(1, true), $rules->plannedStay('Deluxe', Days::date('2027-03-03'), 2, true, true));
        self::assertEquals(new PlannedStay(1, true), $rules->plannedStay('Deluxe', Days::date('2027-03-03'), 1, true, true), 'already at one night');
        self::assertEquals(PlannedStay::undecided(), $rules->plannedStay('Deluxe', Days::date('2027-03-10'), 3, true, true), 'package');
        self::assertEquals(PlannedStay::undecided(), $rules->plannedStay('Deluxe', Days::date('2027-03-19'), 2, true, true), 'holiday orphan off');
        self::assertEquals(PlannedStay::undecided(), $rules->plannedStay('Deluxe', Days::date('2027-03-19'), 2, true, false), 'holiday keeps its own stay');

        $onHolidays = StaySample::rules(['orphan' => ['next_night' => true, 'on_holidays' => true]] + $stay);
        self::assertEquals(new PlannedStay(1, true), $onHolidays->plannedStay('Deluxe', Days::date('2027-03-19'), 2, true, true));
        self::assertEquals(PlannedStay::undecided(), $onHolidays->plannedStay('Deluxe', Days::date('2027-03-19'), 1, true, false));
    }

    public function testAHeldWeekdayStaysAsItIs(): void
    {
        $rules = StaySample::rules(['arrival_rule' => StaySample::ARRIVAL + ['held_weekdays' => ['Standard' => [2]]]]);
        $wednesday = Days::date('2027-03-03');

        self::assertTrue($rules->isHeld('Standard', $wednesday));
        self::assertEquals(PlannedStay::undecided(), $rules->plannedStay('Standard', $wednesday, 1, true, false));
        self::assertFalse($rules->isHeld('Deluxe', $wednesday));
        self::assertEquals(new PlannedStay(2), $rules->plannedStay('Deluxe', $wednesday, 1, true, false));
        self::assertFalse($rules->isHeld('Standard', Days::date('2027-03-02')));
    }

    public function testAClosedDayWithoutItsOwnStayIsLeftToTheArrivalRule(): void
    {
        $friday = Days::date('2027-03-12');
        $closure = ['weekday' => 4, 'restriction' => 'no_checkout', 'rooms' => ['Cottage']];

        self::assertTrue(StaySample::rules(['arrival_rule' => StaySample::ARRIVAL, 'closed_days' => [$closure]])->arrivalDecides('Cottage', $friday));
        $owned = StaySample::rules(['arrival_rule' => StaySample::ARRIVAL, 'closed_days' => [$closure + ['min_stay' => 3]]]);
        self::assertFalse($owned->arrivalDecides('Cottage', $friday));
        self::assertSame(3, $owned->requiredStay('Cottage', $friday));
        self::assertTrue($owned->arrivalDecides('Deluxe', $friday), 'the rule lists its rooms');
    }

    public function testAnOpenDayAppliesOutsidePackagesAndClosedDays(): void
    {
        $open = ['open_days' => [['weekday' => 4, 'rooms' => ['Cottage']]], 'packages' => [['name' => 'P', 'nights' => [['date' => '2027-03-19', 'restriction' => 'none']]]]];

        $rules = StaySample::rules($open);
        self::assertTrue($rules->openDayApplies('Cottage', Days::date('2027-03-12')));
        self::assertFalse($rules->openDayApplies('Deluxe', Days::date('2027-03-12')));
        self::assertFalse($rules->openDayApplies('Cottage', Days::date('2027-03-11')));
        self::assertFalse($rules->openDayApplies('Cottage', Days::date('2027-03-19')), 'package');
        self::assertFalse(StaySample::rules($open + ['closed_days' => [['weekday' => 4, 'restriction' => 'no_checkout', 'rooms' => ['Cottage']]]])->openDayApplies('Cottage', Days::date('2027-03-12')));
    }

    public function testTheFreeSideOfAClosedDay(): void
    {
        $saturday = StaySample::SATURDAY + ['open_dates' => ['2027-03-27' => 'none'], 'except_rooms' => ['Cottage']];
        $rules = StaySample::rules(['closed_days' => [$saturday]]);

        self::assertTrue($rules->orphanAllowed('Deluxe', Days::date('2027-03-13'), true));
        self::assertFalse($rules->orphanAllowed('Deluxe', Days::date('2027-03-27'), true), 'open date');
        self::assertFalse($rules->orphanAllowed('Deluxe', Days::date('2027-03-20'), true), 'holiday, side off on holidays');
        self::assertFalse($rules->orphanAllowed('Deluxe', Days::date('2027-03-20'), false), 'holiday, orphan night off on holidays');
        $saturday['orphan_side']['on_holidays'] = true;
        self::assertTrue(StaySample::rules(['closed_days' => [$saturday]])->orphanAllowed('Deluxe', Days::date('2027-03-20'), true));

        self::assertTrue($rules->closedDayWithPreviousPast('Deluxe', Days::date('2027-03-13'), Days::date('2027-03-13')));
        self::assertFalse($rules->closedDayWithPreviousPast('Deluxe', Days::date('2027-03-13'), Days::date('2027-03-12')), 'the night before still sells');
        self::assertFalse($rules->closedDayWithPreviousPast('Deluxe', Days::date('2027-03-13'), Days::date('2027-03-14')), 'the closed day passed');
        self::assertFalse($rules->closedDayWithPreviousPast('Deluxe', Days::date('2027-03-13'), null));
        self::assertFalse($rules->closedDayWithPreviousPast('Cottage', Days::date('2027-03-13'), Days::date('2027-03-13')), 'room outside the rule');
    }
}
