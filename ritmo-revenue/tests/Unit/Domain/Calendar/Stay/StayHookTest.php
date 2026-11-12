<?php

declare(strict_types=1);

namespace Ritmo\Tests\Unit\Domain\Calendar\Stay;

use PHPUnit\Framework\TestCase;
use Ritmo\Domain\Calendar\Stay\Occupancy;
use Ritmo\Domain\Calendar\Stay\OrphanKind;
use Ritmo\Domain\Calendar\Stay\StayHook;
use Ritmo\Domain\Support\Days;

/**
 * The stay of a date rule the pricing plan deletes and writes again. 1 March 2027 is a Monday;
 * Friday 12 and Saturday 13 March.
 */
final class StayHookTest extends TestCase
{
    private const STAY = [
        'arrival_rule' => StaySample::ARRIVAL,
        'orphan' => ['next_night' => true],
        'closed_days' => [StaySample::SATURDAY],
        'open_days' => [['weekday' => 4, 'rooms' => ['Cottage']]],
    ];

    public function testTheArrivalRuleReplacesTheInheritedStayAndAnOrphanCarriesItsProof(): void
    {
        $hook = new StayHook(StaySample::rules(self::STAY));
        $occupancy = Occupancy::fromInventory(StaySample::nights(['2027-03-03' => [1], '2027-03-04' => [0], '2027-03-08' => [2], '2027-03-09' => [2]]), 4, null);

        $orphan = $hook->rewrite('Deluxe', Days::date('2027-03-03'), 3, null, $occupancy, null);
        self::assertSame([1, OrphanKind::NextNight, 'next'], [$orphan->minStay, $orphan->orphan, $orphan->proof?->neighbour]);
        $monday = $hook->rewrite('Deluxe', Days::date('2027-03-08'), 1, null, $occupancy, null);
        self::assertSame([2, null, null], [$monday->minStay, $monday->orphan, $monday->proof]);
        self::assertNull($hook->rewrite('Deluxe', Days::date('2027-03-19'), null, null, $occupancy, null)->minStay, 'a holiday keeps what it inherits');
    }

    public function testAClosedDayGoesBackToItsOwnStayUnlessItIsTheFreeSide(): void
    {
        $hook = new StayHook(StaySample::rules(self::STAY));
        $soldFriday = Occupancy::fromInventory(StaySample::nights(['2027-03-12' => [0], '2027-03-13' => [2]]), 4, null);
        $freeFriday = Occupancy::fromInventory(StaySample::nights(['2027-03-12' => [1], '2027-03-13' => [2]]), 4, null);
        $saturday = Days::date('2027-03-13');

        $side = $hook->rewrite('Deluxe', $saturday, 1, null, $soldFriday, null);
        self::assertSame([1, OrphanKind::ClosedDay, 'previous'], [$side->minStay, $side->orphan, $side->proof?->neighbour]);
        self::assertSame(2, $hook->rewrite('Deluxe', $saturday, 1, null, $freeFriday, null)->minStay);
        self::assertSame(2, $hook->rewrite('Deluxe', $saturday, null, null, $freeFriday, null)->minStay);
        self::assertNull($hook->rewrite('Deluxe', $saturday, null, null, $soldFriday, null)->minStay, 'no stay is invented on the free side');
        self::assertSame(1, $hook->rewrite('Deluxe', $saturday, 1, null, $freeFriday, $saturday)->minStay, "today's closed day after a past night");
    }

    public function testTheEnforcedOpenDayIsOpenedInTheInheritedMask(): void
    {
        $hook = new StayHook(StaySample::rules(self::STAY));
        $occupancy = Occupancy::fromInventory([], 1, null);

        self::assertSame('1111111', $hook->rewrite('Cottage', Days::date('2027-03-12'), null, '1111011', $occupancy, null)->checkoutMask);
        self::assertSame('1111011', $hook->rewrite('Deluxe', Days::date('2027-03-12'), null, '1111011', $occupancy, null)->checkoutMask);
        self::assertNull($hook->rewrite('Cottage', Days::date('2027-03-12'), null, null, $occupancy, null)->checkoutMask);
    }
}
