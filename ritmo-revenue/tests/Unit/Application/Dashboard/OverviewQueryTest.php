<?php

declare(strict_types=1);

namespace Ritmo\Tests\Unit\Application\Dashboard;

use PHPUnit\Framework\TestCase;
use Ritmo\Application\Approval\PlanEntry;
use Ritmo\Application\Dashboard\Overview\Revenue;
use Ritmo\Application\Dashboard\Todo\TodoService;
use Ritmo\Domain\Model\Restriction;
use Ritmo\Domain\Support\DateRange;
use Ritmo\Domain\Support\Days;
use Ritmo\Tests\Unit\Application\Grid\ScreenWorld;

final class OverviewQueryTest extends TestCase
{
    private ScreenWorld $world;

    protected function setUp(): void
    {
        $this->world = new ScreenWorld();
        $this->world->property();
        $this->world->room('A', 2);
        $this->world->room('B', 1);
        $this->world->calendar('A', 60, 2);
        $this->world->calendar('B', 60, 1, ['2026-11-14' => [0, Restriction::Blackout]]);
        $this->world->booking('8001', 'A', '2026-11-13', '2026-11-15', 1000.0);
        $this->world->booking('8002', 'B', '2026-11-20', '2026-11-22', 900.0);
    }

    public function testTonightAndTheNextNightsShowFreeUnitsClosedNightsAndThePrice(): void
    {
        $this->world->run(['A', 'B'], 30);
        $soon = $this->world->overview()->soon($this->world->properties()->find('inn') ?? self::fail('no property'));

        self::assertCount(4, $soon['nights']);
        self::assertSame('A', $soon['rooms'][0]['code']);
        self::assertSame(1, $soon['rooms'][0]['nights'][0]['sold']);
        self::assertTrue($soon['rooms'][1]['nights'][1]['closed']);
        self::assertSame(480.0, $soon['rooms'][0]['nights'][0]['price']);
        self::assertNotNull($soon['captured_at']);
    }

    public function testTheWindowsCarryOccupancyGoalAndRevenueOnTheBooks(): void
    {
        $windows = $this->world->overview()->windows($this->world->properties()->find('inn') ?? self::fail('no property'));

        self::assertSame([7, 30], array_column($windows, 'days'));
        self::assertSame(1000.0, $windows[0]['revenue'], 'two nights of A inside the 7 days');
        self::assertSame(1, $windows[0]['bookings']);
        self::assertSame(1900.0, $windows[1]['revenue']);
        self::assertSame(4, $windows[1]['room_nights']);
        self::assertSame(60.0, $windows[0]['goal']);
        self::assertCount(2, $windows[0]['rooms']);
    }

    public function testPaceCountsAddUpToTheNightsOfThePlanInSixtyDays(): void
    {
        $runId = $this->world->run(['A', 'B'], 90);
        $pace = $this->world->overview()->pace($this->world->properties()->find('inn') ?? self::fail('no property'));

        self::assertSame($runId, $pace['run_id']);
        self::assertSame(120, $pace['total']);
        self::assertSame(30, $pace['counts']['ahead']);
    }

    public function testNeedsYouListsWaitingPlansCriticalAlertsAndDueItems(): void
    {
        $now = $this->world->clock->now();
        $this->world->approvals()->create(null, 'inn', 'plan', str_repeat('b', 64), Days::date(ScreenWorld::TODAY), null, $now, $now->modify('+5 hours'), [(new PlanEntry(PlanEntry::ITEM, 'A', '2026-11-20', ['floor_pct' => -10]))->toArray()], $now);
        $this->world->alerts()->raise('overbooked', 'critical', 'Overbooked.', [], 'inn', 'A', Days::date('2026-11-20'));
        $this->world->alerts()->raise('push_stale', 'warning', 'Stale.', [], 'inn');
        (new TodoService($this->world->todos(), $this->world->clock))->add('inn', 'Check the heater', '', null, 1);

        $needs = $this->world->overview()->needsYou($this->world->properties()->find('inn') ?? self::fail('no property'));

        self::assertSame(1, $needs['waiting_requests']);
        self::assertSame(1, $needs['waiting_entries']);
        self::assertCount(1, $needs['alerts']);
        self::assertSame('overbooked', $needs['alerts'][0]['code']);
        self::assertSame('Check the heater', $needs['todos'][0]->title);

        $site = $this->world->overview()->forSite();
        self::assertSame(1, $site[0]['critical']);
        self::assertSame(1, $site[0]['warning']);
        self::assertSame(1, $site[0]['waiting']);
    }

    public function testTheLastRunSaysHowManyNightsItWouldWrite(): void
    {
        $property = $this->world->properties()->find('inn') ?? self::fail('no property');
        self::assertNull($this->world->overview()->lastRun($property)['run']);

        $this->world->run(['A'], 10, ['A|2026-11-20' => 'would_write', 'A|2026-11-21' => 'would_write']);
        $last = $this->world->overview()->lastRun($property);

        self::assertSame(2, $last['would_write']);
        self::assertSame(['Done.'], $last['lines']);
    }

    public function testTheNextRunsComeFromTheScheduleInTheLocalTime(): void
    {
        $next = $this->world->overview()->nextRuns($this->world->properties()->find('inn') ?? self::fail('no property'));

        self::assertGreaterThan($this->world->clock->now(), $next['full']);
        self::assertSame('America/Sao_Paulo', $next['full']->getTimezone()->getName());
    }

    public function testRevenueSpreadsATotalOverItsNightsAndUnits(): void
    {
        $world = $this->world;
        $bookings = (new \Ritmo\Infrastructure\Db\Repository\BookingRepository($world->raw))->overlapping(Days::date('2026-11-13'), Days::date('2026-11-30'));
        $rooms = [$world->roomTypes()->find('A') ?? self::fail('no room')];

        self::assertSame(['revenue' => 500.0, 'bookings' => 1, 'room_nights' => 1], Revenue::onTheBooks($bookings, $rooms, DateRange::nights(Days::date('2026-11-14'), 1)));
    }
}
