<?php

declare(strict_types=1);

namespace Ritmo\Tests\Integration\Application;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Ritmo\Application\EmergencySwitch;
use Ritmo\Application\ShadowRun\CollectStage;
use Ritmo\Application\ShadowRun\DecideStage;
use Ritmo\Application\ShadowRun\MeasureStage;
use Ritmo\Application\ShadowRun\PlanItemStatus;
use Ritmo\Application\ShadowRun\RunOutcome;
use Ritmo\Application\ShadowRun\RunInputsReader;
use Ritmo\Application\ShadowRun\RunPipeline;
use Ritmo\Application\ShadowRun\ShadowRunService;
use Ritmo\Application\ShadowRun\ShadowStage;
use Ritmo\Application\ShadowRun\ValidateStage;
use Ritmo\Domain\Model\AnchorOrigin;
use Ritmo\Domain\Model\MarketAnchor;
use Ritmo\Domain\Model\Property;
use Ritmo\Domain\Model\RoomType;
use Ritmo\Domain\Support\DateRange;
use Ritmo\Domain\Support\Days;
use Ritmo\Infrastructure\Db\Repository\AlertRepository;
use Ritmo\Infrastructure\Db\Repository\BookingRepository;
use Ritmo\Infrastructure\Db\Repository\InventorySnapshotRepository;
use Ritmo\Infrastructure\Db\Repository\MarketAnchorRepository;
use Ritmo\Infrastructure\Db\Repository\OrderRepository;
use Ritmo\Infrastructure\Db\Repository\PlanItemRepository;
use Ritmo\Infrastructure\Db\Repository\PropertyRepository;
use Ritmo\Infrastructure\Db\Repository\RoomTypeRepository;
use Ritmo\Infrastructure\Db\Repository\RuleSetRepository;
use Ritmo\Infrastructure\Db\Repository\RunPayloadRepository;
use Ritmo\Infrastructure\Db\Repository\RunRepository;
use Ritmo\Infrastructure\Db\Repository\StepLedgerRepository;
use Ritmo\Infrastructure\Lock\DbLock;
use Ritmo\Infrastructure\Store\ArrayStore;
use Ritmo\Tests\Fakes\StaticConnectorSource;
use Ritmo\Tests\Integration\Connectors\SimulatorTestCase;
use Ritmo\Tests\Unit\Infrastructure\Support\MigratedDatabase;

/**
 * The whole shadow run against the simulator, with the real connectors over cURL behind the
 * read-only guard, on a migrated database: the run completes, stores what it would write, and the
 * simulator's own log shows that only reads arrived.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class ShadowRunOverSimulatorTest extends SimulatorTestCase
{
    public function testWithoutTheCalendarReadOnlyGetRequestsReachTheApis(): void
    {
        [$outcome, $runs, $items] = $this->shadowRun(false);

        self::assertSame(RunOutcome::COMPLETED, $outcome->status, (string) $outcome->error);
        self::assertNotSame([], $items);
        self::assertFalse($runs->find((int) $outcome->runId)['summary']['collect']['pricing_calendar_read'] ?? null);
        $lines = $this->server->lines();
        self::assertNotSame([], $lines);
        foreach ($lines as $line) {
            self::assertStringStartsWith('GET ', $line, 'only reads by GET may reach the APIs');
        }
    }

    public function testWithTheCalendarReadTheOnlyOtherRequestIsThatRead(): void
    {
        [$outcome, $runs] = $this->shadowRun(true);
        $summary = $runs->find((int) $outcome->runId)['summary'] ?? [];

        self::assertSame(RunOutcome::COMPLETED, $outcome->status, (string) $outcome->error);
        self::assertSame(120, $summary['collect']['pricing_nights_read']);
        self::assertTrue($summary['measure']['sold_mismatch_checked']);
        self::assertContains(self::CALENDAR_READ, $this->server->lines());
        $this->assertOnlyReadsReachedTheApis();
        self::assertSame(0, $summary['shadow']['writes_sent']);
    }

    /**
     * @return array{RunOutcome, RunRepository, list<array<string, mixed>>}
     */
    private function shadowRun(bool $calendarRead): array
    {
        $db = MigratedDatabase::create();
        $clock = $this->clock;
        (new PropertyRepository($db, $clock))->save(new Property('sim-inn', 'Simulated Inn', 'UTC', 'BRL'));
        $rooms = new RoomTypeRepository($db, $clock);
        $rooms->save(new RoomType('sim-inn', 'STD', 'Standard', 4, '902001', '901001___902001', 480.0, 320.0, 1600.0, 300.0));
        $rooms->save(new RoomType('sim-inn', 'GDN', 'Garden Suite', 2, '902002', '901001___902002', 700.0, 450.0, 2400.0, 420.0));
        $anchors = new MarketAnchorRepository($db, $clock);
        foreach (DateRange::nights(Days::date('2027-02-28'), 61) as $night) {
            $anchors->save('sim-inn', new MarketAnchor($night, 420.0, 500.0, 600.0, 700.0, 900.0, 8, Days::date('2027-02-27'), AnchorOrigin::Measured));
        }

        $http = $this->guardedHttp($calendarRead);
        $connectors = new StaticConnectorSource($this->beds24(null, $http), $this->priceLabs($http), $calendarRead);
        $runs = new RunRepository($db);
        $snapshots = new InventorySnapshotRepository($db);
        $bookings = new BookingRepository($db);
        $planItems = new PlanItemRepository($db, $clock);
        $switch = new EmergencySwitch(new ArrayStore());
        $pipeline = new RunPipeline([
            new CollectStage($connectors, $snapshots, $bookings, $clock),
            new MeasureStage($snapshots, $bookings, new AlertRepository($db, $clock), $clock),
            new DecideStage(new RunInputsReader($snapshots, $anchors, new StepLedgerRepository($db), new OrderRepository($db, $clock)), $clock, 'beds24'),
            new ValidateStage(),
            new ShadowStage($planItems, new RunPayloadRepository($db, $clock)),
        ], $runs, $db, $switch, $clock, $this->logger);
        $service = new ShadowRunService(new PropertyRepository($db, $clock), $rooms, new RuleSetRepository($db, $clock), $runs, new DbLock($db, $clock), $switch, $pipeline, $clock, $this->logger);

        $outcome = $service->run('sim-inn');
        $items = array_values(array_filter($planItems->forRun((int) $outcome->runId), static fn (array $item): bool => $item['status'] === PlanItemStatus::WOULD_WRITE));

        return [$outcome, $runs, $items];
    }
}
