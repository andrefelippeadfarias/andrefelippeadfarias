<?php

declare(strict_types=1);

namespace Ritmo\Tests\Unit\Application\Grid;

use PHPUnit\Framework\TestCase;
use Ritmo\Application\Grid\NightCell;
use Ritmo\Application\Grid\NightFilter;
use Ritmo\Application\Grid\PlannedNight;
use Ritmo\Domain\Model\Restriction;
use Ritmo\Domain\Write\ThirdParty\DetectedFreeze;

final class NightGridQueryTest extends TestCase
{
    private ScreenWorld $world;

    protected function setUp(): void
    {
        $this->world = new ScreenWorld();
        $this->world->property();
        foreach (['A', 'B', 'C'] as $room) {
            $this->world->room($room);
            $this->world->calendar($room, 90, 2, ['2026-11-15' => [0, Restriction::Blackout]]);
        }
        $this->world->booking('7001', 'A', '2026-11-13', '2026-11-15', 1200.0);
        $this->world->booking('7002', 'A', '2026-11-14', '2026-11-15', 500.0);
        $this->world->run(['A', 'B', 'C'], 60, ['A|2026-11-20' => 'would_write', 'B|2026-11-21' => 'would_write', 'C|2026-11-22' => 'rejected']);
    }

    public function testTheGridHasEveryRoomAndNightWithOccupancyPriceAndPlan(): void
    {
        $grid = $this->world->grid()->window('inn', $this->world->night('2026-11-13'), 30, [], NightFilter::from('all'));

        self::assertCount(3, $grid->rooms);
        self::assertCount(30, $grid->nights);
        self::assertSame(90, $grid->shown());
        $first = $grid->cell('A', '2026-11-13');
        self::assertInstanceOf(NightCell::class, $first);
        self::assertSame(1, $first->unitsSold);
        self::assertSame(2, $grid->cell('A', '2026-11-14')?->unitsSold);
        self::assertTrue($grid->cell('A', '2026-11-14')?->soldOut());
        self::assertSame(480.0, $first->priceNow, 'the price the last run read');
        self::assertSame(0, $first->daysOut);
        self::assertTrue($grid->cell('B', '2026-11-15')?->closedWithoutBooking(), 'a blackout without booking');
        self::assertSame(PlannedNight::WOULD_WRITE, $grid->cell('A', '2026-11-20')?->planned->status);
        self::assertSame('down', $grid->cell('A', '2026-11-20')?->planned->direction());
        self::assertSame(-30, $grid->cell('A', '2026-11-20')?->planned->floorPercent);
        self::assertSame(PlannedNight::REJECTED, $grid->cell('C', '2026-11-22')?->planned->status);
    }

    public function testNightsBeyondThePlanAreNotPricedAndKeepThePmsPrice(): void
    {
        $grid = $this->world->grid()->window('inn', $this->world->night('2026-12-20'), 30, ['A'], NightFilter::from('all'));

        $cell = $grid->cell('A', '2027-01-15');
        self::assertNotNull($cell);
        self::assertFalse($cell->priced());
        self::assertSame(500.0, $cell->priceNow, 'the PMS price when the run did not price the night');
        self::assertCount(1, $grid->rooms);
    }

    public function testTheWouldChangeFilterKeepsOnlyTheNightsTheRunWouldWrite(): void
    {
        $grid = $this->world->grid()->window('inn', $this->world->night('2026-11-13'), 30, [], NightFilter::from('would_change'));

        self::assertSame(2, $grid->shown());
        self::assertNull($grid->cell('A', '2026-11-13'));
        self::assertNotNull($grid->cell('B', '2026-11-21'));
        self::assertSame(1, $this->world->grid()->window('inn', $this->world->night('2026-11-13'), 30, [], NightFilter::from('refused'))->shown());
        self::assertSame('all', NightFilter::from('anything')->only);
        self::assertContains('status:very_behind', NightFilter::values());
    }

    public function testFreezesOrdersAndAlertsMarkTheirNights(): void
    {
        $this->world->freezes()->add('inn', new DetectedFreeze('B', [$this->world->night('2026-11-18')], $this->world->clock->now(), $this->world->clock->now()->modify('+3 days'), ['floor']));
        $this->world->alerts()->raise('overbooked', 'critical', 'Overbooked.', [], 'inn', 'C', $this->world->night('2026-11-19'));

        $grid = $this->world->grid()->window('inn', $this->world->night('2026-11-13'), 30, [], NightFilter::from('all'));

        self::assertTrue($grid->cell('B', '2026-11-18')?->frozen);
        self::assertFalse($grid->cell('B', '2026-11-19')?->frozen);
        self::assertSame(1, $grid->cell('C', '2026-11-19')?->alertCount);
        self::assertSame(1, $this->world->grid()->window('inn', $this->world->night('2026-11-13'), 30, [], NightFilter::from('frozen'))->shown());
    }

    public function testTheStatementCountDoesNotGrowWithTheNights(): void
    {
        foreach (['D', 'E', 'F', 'G', 'H', 'I', 'J', 'K', 'L'] as $room) {
            $this->world->room($room);
        }
        $this->world->db->statements = 0;
        $this->world->grid()->window('inn', $this->world->night('2026-11-13'), 30, [], NightFilter::from('all'));
        $thirty = $this->world->db->statements;
        $this->world->db->statements = 0;
        $this->world->grid()->window('inn', $this->world->night('2026-11-13'), 90, [], NightFilter::from('all'));

        self::assertSame($thirty, $this->world->db->statements);
        self::assertLessThanOrEqual(25, $thirty, 'admin spec 9.1: at most 25 statements for 12 rooms');
    }

    public function testRoomsArePagedByTwelve(): void
    {
        foreach (range(1, 12) as $n) {
            $this->world->room('R' . $n);
        }
        $second = $this->world->grid()->window('inn', $this->world->night('2026-11-13'), 7, [], NightFilter::from('all'), 2);

        self::assertSame(15, $second->roomsInAll);
        self::assertSame(12, $second->roomOffset);
        self::assertCount(3, $second->rooms);
    }

    public function testTheDetailShowsBookingsByIdAndChannelOnlyAndTheRunsDecision(): void
    {
        $detail = $this->world->detail()->detail('inn', 'A', $this->world->night('2026-11-14'));

        self::assertSame([['ref' => '7001', 'channel' => 'booking', 'units' => 1], ['ref' => '7002', 'channel' => 'booking', 'units' => 1]], $detail->bookings);
        self::assertSame('no_change', $detail->decisions[0]['status']);
        self::assertSame('PACE_STEP', $detail->decisions[0]['reason']);
        self::assertSame(600.0, $detail->room->base);
    }

    public function testAnUnknownRoomOrPropertyIsRefused(): void
    {
        $this->expectException(\OutOfBoundsException::class);
        $this->world->detail()->detail('inn', 'Z', $this->world->night('2026-11-14'));
    }
}
