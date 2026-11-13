<?php

declare(strict_types=1);

namespace Ritmo\Tests\Unit\Application\Report;

use PHPUnit\Framework\TestCase;
use Ritmo\Application\Report\Store\TargetRow;
use Ritmo\Domain\Model\BookingStatus;
use Ritmo\Domain\Report\Config\ReportsConfig;
use Ritmo\Domain\Report\Month\MonthReport;
use Ritmo\Domain\Report\Occupancy\RowKind;
use Ritmo\Domain\Report\Targets\Scenario;
use Ritmo\Domain\Support\Days;

final class ReportServiceTest extends TestCase
{
    private ReportWorld $world;

    protected function setUp(): void
    {
        $this->world = new ReportWorld(ReportsConfig::fromArray(['occupancy' => ['map_nights' => 3, 'groups' => [['label' => 'With a view', 'tags' => ['view']]]]]));
        $w = $this->world;
        $w->book('B1', 'STD', '2026-11-14', 2, 900.0, '2026-11-02T15:00:00Z');
        $w->book('B2', 'GDN', '2026-11-20', 1, 700.0, '2026-11-10T12:00:00Z', BookingStatus::NewBooking, 'direct');
        $w->book('B3', 'STD', '2026-12-05', 3, 1500.0, '2026-11-11T12:00:00Z', BookingStatus::Cancelled, 'airbnb', '2026-11-12T10:00:00Z');
        $w->book('B4', 'STD', '2026-11-13', 1, 400.0, '2026-10-20T12:00:00Z', BookingStatus::Request);
    }

    public function testTheMonthReportCountsTheStoredBookingsOfTheProperty(): void
    {
        $report = $this->world->service->month($this->world->scope, '2026-11', $this->world->now());
        $made = $report->made[MonthReport::SITE]->overall;
        self::assertSame([3, 1, 2, 3, 1600.0], [$made->bookings, $made->cancelled, $made->active, $made->roomNights, $made->revenue]);
        self::assertSame(['airbnb', 'booking', 'direct_api'], array_keys($report->made['inn']->byChannel));
        self::assertSame(1, $report->cancellations['inn']->count);
        self::assertSame(1600.0, $report->stays['inn']->aheadRevenue);
        self::assertSame(5, $report->stays['inn']->capacity);
    }

    public function testTargetsFeedTheMonthTheReviewAndTheForecast(): void
    {
        $w = $this->world;
        self::assertNull($w->targets->save('inn', new TargetRow('2026-11', ['minimum' => 10000.0, 'market_sold_percent' => 40.0]), 7, $w->now()));
        $before = $w->targets->save('inn', new TargetRow('2026-12', ['minimum' => 12000.0, 'market_occupancy_ly' => 50.0, 'market_adr_ly' => 400.0, 'adjust_goal' => 500.0, 'market_sold_percent' => 10.0]), 7, $w->now());
        self::assertNull($before);
        self::assertSame(12000.0, $w->targets->save('inn', new TargetRow('2026-12', ['minimum' => 13000.0, 'market_occupancy_ly' => 50.0, 'market_adr_ly' => 400.0, 'adjust_goal' => 500.0, 'market_sold_percent' => 10.0]), 7, $w->now())?->value('minimum'));

        self::assertSame(8400.0, $w->service->month($w->scope, '2026-11', $w->now())->stays['inn']->gapToMinimum);
        $targets = $w->service->targets($w->scope, $w->now());
        $december = $targets['forecast'][1];
        self::assertSame('2026-12', $december->month);
        self::assertSame(31000, $december->of(Scenario::Floor), '50% x 400 x 5 units x 31 days');
        self::assertSame(31500, $december->of(Scenario::Goal));
        self::assertSame(['inn'], $targets['forecast'][0]->missing, 'November has no market inputs');
        self::assertSame(13000.0, $targets['targets'][1]->minimum);

        $review = $w->service->review($w->scope, $w->now());
        self::assertCount(13, $review->rows);
        self::assertNull($review->rows[0]->reading, 'never for the current month');
        self::assertSame(13000.0, $review->rows[1]->minimum);
        self::assertSame('behind', $review->rows[1]->reading?->value);
    }

    public function testASavedReviewIsComparedWithByTheNextOne(): void
    {
        $w = $this->world;
        self::assertTrue($w->service->reviewDue($w->scope, Days::date('2026-11-13'))->due);
        $w->service->saveReview($w->scope, $w->now()->modify('-20 days'), 7);
        $due = $w->service->reviewDue($w->scope, Days::date('2026-11-13'));
        self::assertTrue($due->due);
        self::assertSame(20, $due->daysSinceLast);
        $w->book('B5', 'GDN', '2026-12-20', 2, 2000.0, '2026-11-12T12:00:00Z');
        $review = $w->service->review($w->scope, $w->now());
        self::assertSame('2026-10-24', Days::key($review->previousDay));
        self::assertSame(2000.0, $review->rows[1]->pickupSinceLast);
    }

    public function testOccupancyUsesTheBookedUnitsTheGroupsAndTheMap(): void
    {
        $w = $this->world;
        $w->stock('STD', ['2026-11-13' => 0, '2026-11-14' => 2, '2026-11-15' => 2]);
        $w->stock('GDN', ['2026-11-13' => 2, '2026-11-14' => 2]);
        $result = $w->service->occupancy($w->scope, Days::date('2026-11-13'));
        $kinds = array_map(static fn ($r): RowKind => $r->kind, $result['rows']);
        self::assertSame([RowKind::Room, RowKind::Room, RowKind::Group, RowKind::Property], $kinds);
        $std = $result['rows'][0]->windows[7];
        self::assertSame([2, 9], [$std->sold, $std->capacity], 'the closed night 13 with nothing booked is no sale (3 units still count)');
        self::assertCount(3, $result['map']);
        self::assertSame(0, $result['map'][0]->cells['STD']->free, 'blocked');
        self::assertNull($result['map'][2]->cells['GDN']->free, 'not read');
    }
}
