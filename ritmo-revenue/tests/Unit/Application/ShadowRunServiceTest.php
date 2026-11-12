<?php

declare(strict_types=1);

namespace Ritmo\Tests\Unit\Application;

use OutOfBoundsException;
use PHPUnit\Framework\TestCase;
use Ritmo\Application\ShadowRun\DecideStage;
use Ritmo\Application\ShadowRun\PlanItemStatus;
use Ritmo\Application\ShadowRun\RunOutcome;
use Ritmo\Application\ShadowRun\ShadowRunService;
use Ritmo\Contracts\Exception\ConnectorException;
use Ritmo\Domain\Pricing\RuleCode;
use Ritmo\Domain\Support\Days;
use Ritmo\Infrastructure\Db\Repository\RunRepository;
use Ritmo\Tests\Fakes\InMemoryPmsConnector;
use Ritmo\Tests\Fakes\InMemoryPricingConnector;
use Ritmo\Tests\Fakes\StaticConnectorSource;

final class ShadowRunServiceTest extends TestCase
{
    private const READS_PMS = ['readInventory', 'readBookingsModifiedSince'];

    private ShadowRunWorld $world;

    protected function setUp(): void
    {
        $this->world = new ShadowRunWorld();
    }

    public function testAShadowRunGoesThroughEveryStageAndOnlyReads(): void
    {
        $outcome = $this->world->service()->run(ShadowRunWorld::PROPERTY);

        self::assertSame(RunOutcome::COMPLETED, $outcome->status, (string) $outcome->error);
        $run = $this->world->runs->find((int) $outcome->runId);
        self::assertSame(RunRepository::COMPLETED, $run['status'] ?? null);
        self::assertSame('shadow', $run['stage'] ?? null);
        self::assertSame(ShadowRunWorld::FIRST_NIGHT, $run['run_day']?->format('Y-m-d'), 'today in the property timezone');
        self::assertSame(['collect', 'measure', 'decide', 'validate', 'shadow'], array_keys($run['summary']));
        self::assertSame([], $this->world->runs->state((int) $outcome->runId), 'the state between stages is dropped at the end');

        self::assertSame(self::READS_PMS, $this->world->pms->calls(), 'the PMS was only read');
        self::assertSame(['listListings', 'readOverrides', 'readOverrides'], $this->world->pricing->calls(), 'the pricing system was only read, and its calendar not at all');
        self::assertSame(0, $run['summary']['shadow']['writes_sent']);
        self::assertFalse($run['summary']['collect']['pricing_calendar_read']);
    }

    public function testItStoresWhatItWouldWriteWithTheRuleTrace(): void
    {
        $outcome = $this->world->service()->run(ShadowRunWorld::PROPERTY);
        $items = $this->world->planItems->forRun((int) $outcome->runId);
        $summary = $this->world->runs->find((int) $outcome->runId)['summary'] ?? [];

        $wouldWrite = array_values(array_filter($items, static fn (array $item): bool => $item['status'] === PlanItemStatus::WOULD_WRITE));
        self::assertNotSame([], $wouldWrite);
        self::assertSame($summary['validate'][PlanItemStatus::WOULD_WRITE], count(array_unique(array_map(static fn (array $i): string => $i['room_code'] . $i['night']->format('Y-m-d'), $wouldWrite))));
        foreach ($items as $item) {
            self::assertContains($item['room_code'], ['STD', 'STE'], 'the unmapped room is left out');
            self::assertContains($item['field'], ['floor', 'ceiling']);
            self::assertSame(RuleCode::PACE_STEP, $item['reason']);
            self::assertSame($item['status'] === PlanItemStatus::WOULD_WRITE, $item['details']['would_write']);
            $codes = array_column($item['details']['rule_trace'], 'code');
            self::assertSame(RuleCode::NIGHT, $codes[0]);
            self::assertContains(RuleCode::PACE_STEP, $codes);
            self::assertFalse(ShadowRunWorld::isWeekend($item['night']), 'weekend nights have no market data');
        }
        $floor = array_values(array_filter($wouldWrite, static fn (array $i): bool => $i['field'] === 'floor'))[0];
        self::assertSame(420.0, $floor['current'] ?? $floor['details']['current_price'], 'the current price comes from the PMS');
        self::assertIsInt($floor['details']['min_percent']);
    }

    public function testNightsWithoutAnchorAreNoMarketDataAndGiveNoItem(): void
    {
        $outcome = $this->world->service()->run(ShadowRunWorld::PROPERTY);
        $decide = $this->world->runs->find((int) $outcome->runId)['summary']['decide'] ?? [];

        $weekendNights = count(array_filter(iterator_to_array(ShadowRunWorld::nights()), ShadowRunWorld::isWeekend(...)));
        self::assertSame(2 * $weekendNights, $decide['no_market_data']);
        self::assertSame(2 * ShadowRunService::HORIZON_NIGHTS, $decide['nights']);
        self::assertArrayNotHasKey(RuleCode::NO_MARKET_ANCHOR, $decide['by_reason'], 'the engine is not asked about such nights');
    }

    public function testCollectStoresTheCalendarAndTheBookingsAndMeasureRaisesAlerts(): void
    {
        $outcome = $this->world->service()->run(ShadowRunWorld::PROPERTY);
        $summary = $this->world->runs->find((int) $outcome->runId)['summary'] ?? [];

        $calendar = $this->world->snapshots->latest('STD', Days::date('2027-03-01'), Days::date('2027-04-29'));
        self::assertCount(60, $calendar);
        self::assertSame(420.0, $calendar['2027-03-01']->pmsPrice);
        self::assertSame(2, $summary['collect']['bookings_read']);
        self::assertNotNull($this->world->bookings->find('memory-pms', 'B1'));

        self::assertSame([['room' => 'STD', 'night' => '2027-03-05', 'units_over' => 1]], $summary['measure']['overbooked']);
        self::assertSame('push_disabled', $summary['measure']['push']['STE']['problem']);
        self::assertNull($summary['measure']['push']['STD']['problem'], 'pushed two hours ago');
        $codes = array_column($this->world->alerts->unresolved(ShadowRunWorld::PROPERTY), 'code');
        sort($codes);
        self::assertSame(['overbooked', 'push_disabled'], $codes);
        self::assertSame(28, $summary['measure']['occupancy']['rooms']['STD'][7]['capacity'], 'seven nights of four units');
        self::assertFalse($summary['measure']['sold_mismatch_checked']);
    }

    public function testThePricingCalendarIsReadOnlyWhenAllowedAndThenFeedsTheChecks(): void
    {
        $service = $this->world->service(new StaticConnectorSource($this->world->pms, $this->world->pricing, true));
        $outcome = $service->run(ShadowRunWorld::PROPERTY);
        $summary = $this->world->runs->find((int) $outcome->runId)['summary'] ?? [];

        self::assertSame(RunOutcome::COMPLETED, $outcome->status, (string) $outcome->error);
        self::assertSame(['listListings', 'readOverrides', 'readPrices', 'readOverrides', 'readPrices'], $this->world->pricing->calls());
        self::assertSame(120, $summary['collect']['pricing_nights_read']);
        self::assertTrue($summary['measure']['sold_mismatch_checked']);
        self::assertNotSame([], $summary['measure']['sold_mismatches'], 'the pricing system counts one sale of STD every night');
    }

    public function testARequestThatDiedLeavesARunThatResumesFromTheNextStage(): void
    {
        $first = $this->world->service()->run(ShadowRunWorld::PROPERTY, 0);
        self::assertSame(RunOutcome::RUNNING, $first->status);
        self::assertSame('collect', $first->stage, 'one stage per request when the budget is spent');
        self::assertSame('collect', $this->world->runs->find((int) $first->runId)['stage'] ?? null);

        // A new request, with new objects: the collect stage must not run again.
        $pms = new InMemoryPmsConnector();
        $pricing = new InMemoryPricingConnector();
        $second = $this->world->service(new StaticConnectorSource($pms, $pricing))->run(ShadowRunWorld::PROPERTY);

        self::assertSame(RunOutcome::COMPLETED, $second->status, (string) $second->error);
        self::assertSame($first->runId, $second->runId, 'the same run went on');
        self::assertSame([], $pms->calls());
        self::assertSame([], $pricing->calls());
        self::assertNotSame([], $this->world->planItems->forRun((int) $second->runId), 'decided from what the first request stored');
        self::assertCount(1, $this->world->runs->latest(ShadowRunWorld::PROPERTY));
    }

    public function testAFailingStageEndsTheRunWithoutStoringAnything(): void
    {
        $broken = new StaticConnectorSource($this->world->pms, new FailingPricingConnector(new ConnectorException('PriceLabs did not answer.', 'pricelabs', 503, true)));

        $outcome = $this->world->service($broken)->run(ShadowRunWorld::PROPERTY);

        self::assertSame(RunOutcome::FAILED, $outcome->status);
        self::assertSame('Stage "collect": PriceLabs did not answer.', $outcome->error);
        self::assertSame(RunRepository::FAILED, $this->world->runs->find((int) $outcome->runId)['status'] ?? null);
        self::assertSame([], $this->world->snapshots->latest('STD', Days::date('2027-03-01'), Days::date('2027-04-29')));
        self::assertNull($this->world->runs->openRun(ShadowRunWorld::PROPERTY, ShadowRunService::KIND));

        $again = $this->world->service()->run(ShadowRunWorld::PROPERTY);
        self::assertSame(RunOutcome::COMPLETED, $again->status);
        self::assertNotSame($outcome->runId, $again->runId, 'a failed run is not resumed: the next request starts anew');
    }

    public function testMissingCredentialsFailTheRunWithAClearMessage(): void
    {
        $outcome = $this->world->service(new StaticConnectorSource(null, null))->run(ShadowRunWorld::PROPERTY);

        self::assertSame(RunOutcome::FAILED, $outcome->status);
        self::assertSame('Stage "collect": The PMS connection is not configured.', $outcome->error);
    }

    public function testTheEmergencySwitchStopsTheRunBeforeItsNextStage(): void
    {
        $stages = $this->world->stages();
        $stages[1] = new SwitchingStage($stages[1], $this->world->switch->engage(...));

        $outcome = $this->world->service(null, $stages)->run(ShadowRunWorld::PROPERTY);

        self::assertSame(RunOutcome::STOPPED, $outcome->status);
        self::assertSame('measure', $outcome->stage);
        self::assertSame('Stopped by the emergency switch before the stage "decide".', $outcome->error);
        self::assertSame(RunRepository::STOPPED, $this->world->runs->find((int) $outcome->runId)['status'] ?? null);
        self::assertSame([], $this->world->planItems->forRun((int) $outcome->runId));

        $blocked = $this->world->service()->run(ShadowRunWorld::PROPERTY);
        self::assertSame(RunOutcome::BLOCKED, $blocked->status, 'no run starts while the switch is engaged');
        self::assertNull($blocked->runId);

        $this->world->switch->release();
        self::assertSame(RunOutcome::COMPLETED, $this->world->service()->run(ShadowRunWorld::PROPERTY)->status);
    }

    public function testTheConstantEngagesTheSwitchWithoutAnyOption(): void
    {
        $world = new ShadowRunWorld(true);
        $world->switch->release();

        $outcome = $world->service()->run(ShadowRunWorld::PROPERTY);

        self::assertSame(RunOutcome::BLOCKED, $outcome->status);
        self::assertSame([], $world->runs->latest());
        self::assertSame([], $world->pms->calls());
    }

    public function testOnlyOneRequestRunsAPropertyAtATimeUntilTheLockExpires(): void
    {
        $held = $this->world->locks->acquire(ShadowRunService::lockKey(ShadowRunWorld::PROPERTY), 60);
        self::assertNotNull($held);

        $busy = $this->world->service()->run(ShadowRunWorld::PROPERTY);
        self::assertSame(RunOutcome::BUSY, $busy->status);
        self::assertSame([], $this->world->pms->calls());

        $this->world->clock->advance(61);
        self::assertSame(RunOutcome::COMPLETED, $this->world->service()->run(ShadowRunWorld::PROPERTY)->status, 'a lock left by a dead request expires');
        self::assertFalse($this->world->locks->isLocked(ShadowRunService::lockKey(ShadowRunWorld::PROPERTY)), 'the lock is released at the end');
    }

    public function testARunLeftOpenOnAnEarlierDayIsClosedAndANewOneStarts(): void
    {
        $old = $this->world->service()->run(ShadowRunWorld::PROPERTY, 0);
        $this->world->clock->advance(86400);

        $new = $this->world->service()->run(ShadowRunWorld::PROPERTY);

        self::assertNotSame($old->runId, $new->runId);
        self::assertSame(RunOutcome::COMPLETED, $new->status, (string) $new->error);
        $closed = $this->world->runs->find((int) $old->runId);
        self::assertSame(RunRepository::FAILED, $closed['status'] ?? null);
        self::assertSame('The day of the property changed before the run ended; a new run was started.', $closed['error'] ?? null);
        self::assertSame('2027-03-02', $this->world->runs->find((int) $new->runId)['run_day']?->format('Y-m-d'));
    }

    public function testOpenRunsAreResumedByTheScheduledHook(): void
    {
        $open = $this->world->service()->run(ShadowRunWorld::PROPERTY, 0);

        $outcomes = $this->world->service()->resumeOpenRuns();

        self::assertSame([ShadowRunWorld::PROPERTY], array_keys($outcomes));
        self::assertSame($open->runId, $outcomes[ShadowRunWorld::PROPERTY]->runId);
        self::assertSame(RunOutcome::COMPLETED, $outcomes[ShadowRunWorld::PROPERTY]->status);
        self::assertSame([], $this->world->service()->resumeOpenRuns(), 'nothing left to resume');
    }

    public function testAPropertyWithoutMappedRoomsOrUnknownFails(): void
    {
        $this->world->db->execute('UPDATE wp_ritmo_room_types SET pms_ref = ?', ['']);

        $outcome = $this->world->service()->run(ShadowRunWorld::PROPERTY);

        self::assertSame(RunOutcome::FAILED, $outcome->status);
        self::assertSame('No room type of the property is mapped to the PMS.', $outcome->error);

        $this->expectException(OutOfBoundsException::class);
        $this->world->service()->run('nowhere');
    }

    public function testTheNightsOfTheRunAreDecidedFromTheStoredSnapshotOfThatRun(): void
    {
        $outcome = $this->world->service()->run(ShadowRunWorld::PROPERTY);
        $decide = $this->world->runs->find((int) $outcome->runId)['summary']['decide'] ?? [];

        self::assertArrayNotHasKey(DecideStage::NO_INVENTORY, $decide['by_reason']);
        self::assertArrayNotHasKey(DecideStage::NO_CURRENT_PRICE, $decide['by_reason']);
        self::assertArrayHasKey(RuleCode::SOLD_OUT_PENDING_M2, $decide['by_reason'], 'the sold-out weekday night is left for M2');
    }
}

