<?php

declare(strict_types=1);

namespace Ritmo\Tests\Integration\Write;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Ritmo\Application\Write\Execute\StopReason;
use Ritmo\Application\Write\Execute\WriteOutcome;
use Ritmo\Application\Write\Gate\WriteRefused;
use Ritmo\Application\Write\Journal\OpStatus;
use Ritmo\Application\Write\Schedule\CalendarChange;
use Ritmo\Domain\Plan\PlanDeletion;
use Ritmo\Domain\Plan\ValidatedPlan;
use Ritmo\Domain\Support\Days;
use Ritmo\Tests\Integration\Write\Support\SimWorld;

/**
 * The run stops at the first failure and sends nothing after it (WRITE.md, failure table): a 400
 * in the middle, a 429 of either system, a lost answer, the emergency switch between two steps,
 * the property's midnight between two steps or between building and writing, the time left, a
 * listing not priced yet, a body refused run after run.
 */
final class WriteFailuresTest extends TestCase
{
    private SimWorld $world;

    protected function setUp(): void
    {
        $this->world = new SimWorld();
    }

    /**
     * Three steps: STD (10 March), GDN (11 March), then the PMS block (12 March).
     *
     * @return array{0: ValidatedPlan, 1: list<CalendarChange>}
     */
    private function threeSteps(): array
    {
        $plan = $this->world->validate([SimWorld::item('STD', '2027-03-10', 450.0), SimWorld::item('GDN', '2027-03-11', 600.0)]);
        $night = Days::date('2027-03-12');

        return [$plan, [new CalendarChange('STD', '902001', $night, $night, minStay: 2, minStayBefore: 1)]];
    }

    /**
     * @return list<string>
     */
    private static function statuses(WriteOutcome $outcome): array
    {
        return array_map(static fn ($r): string => $r->status->value, $outcome->results);
    }

    public function testA400InTheMiddleStopsTheRunAndStoresNothingOfThatCall(): void
    {
        [$plan, $pms] = $this->threeSteps();
        $this->world->apis->fault('POST', '/pricelabs/v1/listings/' . SimWorld::GDN . '/overrides', 400);

        $outcome = $this->world->write($plan, $pms);

        self::assertSame(StopReason::Rejected, $outcome->stopReason);
        self::assertSame(['stored', 'rejected', 'abandoned'], self::statuses($outcome));
        self::assertCount(2, $this->world->apis->writeLines(), 'nothing after the refused call');
        self::assertArrayNotHasKey('2027-03-11', $this->world->stored(SimWorld::GDN));
        self::assertContains('write_stopped', $outcome->alerts);
    }

    public function testA429OfEitherSystemStopsTheRun(): void
    {
        [$plan, $pms] = $this->threeSteps();
        $this->world->apis->fault('POST', '/pricelabs/v1/listings/' . SimWorld::STD . '/overrides', 429);
        $outcome = $this->world->write($plan, $pms);
        self::assertSame(StopReason::RateLimited, $outcome->stopReason);
        self::assertSame(['rate_limited', 'abandoned', 'abandoned'], self::statuses($outcome));
        self::assertCount(1, $this->world->apis->writeLines());

        $other = new SimWorld();
        $plan = $other->validate([SimWorld::item('STD', '2027-03-10', 450.0)]);
        $other->apis->fault('POST', '/beds24/v2/inventory/rooms/calendar', 429);
        $night = Days::date('2027-03-12');
        $outcome = $other->write($plan, [new CalendarChange('STD', '902001', $night, $night, minStay: 2), new CalendarChange('GDN', '902002', $night, $night, minStay: 3)]);
        self::assertSame(StopReason::RateLimited, $outcome->stopReason);
        self::assertSame(['stored', 'rate_limited'], self::statuses($outcome));
    }

    public function testALostAnswerIsUnknownAndTheResumeReadsItAsApplied(): void
    {
        [$plan, $pms] = $this->threeSteps();
        $this->world->apis->timeout('POST', '/pricelabs/v1/listings/' . SimWorld::STD . '/overrides', true);

        $outcome = $this->world->write($plan, $pms);
        self::assertSame(StopReason::Unknown, $outcome->stopReason);
        self::assertSame(['unknown', 'abandoned', 'abandoned'], self::statuses($outcome));
        self::assertContains('write_unknown_outcome', $outcome->alerts);

        $report = $this->world->resume();
        self::assertSame([OpStatus::Stored], array_values($report->rows), 'only the unknown call is read again; the others were never sent');
        self::assertSame(['stored', 'abandoned', 'abandoned'], array_column($this->world->ops->ofRun(1), 'status'));
    }

    public function testTheEmergencySwitchBetweenTwoStepsStopsTheRunAndKillsTheToken(): void
    {
        [$plan, $pms] = $this->threeSteps();
        $issued = $this->world->issue($plan, $pms);
        $beats = 0;
        $switch = $this->world->switch;
        $outcome = $this->world->execute($issued, $plan, static function () use (&$beats, $switch): bool {
            if (++$beats === 2) {
                $switch->engage();
            }

            return true;
        });

        self::assertSame(StopReason::KillSwitch, $outcome->stopReason);
        self::assertSame(['stored', 'abandoned', 'abandoned'], self::statuses($outcome));
        self::assertCount(1, $this->world->apis->writeLines());
        self::assertFalse($this->world->gate->isLive($issued->token));
    }

    public function testThePropertysMidnightBetweenTwoStepsStopsTheRun(): void
    {
        $world = new SimWorld(now: '2027-03-03T23:57:00Z');
        $plan = $world->validate([SimWorld::item('STD', '2027-03-10', 450.0), SimWorld::item('GDN', '2027-03-11', 600.0)]);
        $issued = $world->issue($plan);
        $clock = $world->clock;
        $beats = 0;
        $outcome = $world->execute($issued, $plan, static function () use (&$beats, $clock): bool {
            if (++$beats === 2) {
                $clock->advance(240);
            }

            return true;
        });

        self::assertSame(StopReason::DayTurned, $outcome->stopReason);
        self::assertSame(['stored', 'abandoned'], self::statuses($outcome));
        self::assertCount(1, $world->apis->writeLines());
    }

    public function testAPlanBuiltBeforeMidnightIsNeverWrittenAfterIt(): void
    {
        $world = new SimWorld(now: '2027-03-03T23:59:00Z');
        $plan = $world->validate([SimWorld::item('STD', '2027-03-10', 450.0)]);
        $world->clock->advance(120);

        try {
            $world->issue($plan);
            self::fail('a plan of yesterday got a token');
        } catch (WriteRefused $refused) {
            self::assertSame(WriteRefused::STALE_PLAN, $refused->reason);
        }
        self::assertSame([], $world->apis->writeLines());
    }

    public function testAGroupStartsOnlyWithTimeForBothHalves(): void
    {
        $plan = $this->world->validate([SimWorld::item('STD', '2027-03-05', 500.0, 800.0)], [new PlanDeletion('STD', Days::date('2027-03-05'), 'free_ceiling')]);
        $issued = $this->world->issue($plan);
        $deadline = $this->world->clock->now()->modify('+200 seconds');

        $outcome = $this->world->execute($issued, $plan, null, $deadline);

        self::assertSame(StopReason::TimeBudget, $outcome->stopReason);
        self::assertSame(['abandoned', 'abandoned'], self::statuses($outcome));
        self::assertSame([], $this->world->apis->writeLines());
    }

    public function testAListingNotPricedYetStopsTheRunUnlessItIsRecalculatedFirst(): void
    {
        $world = new SimWorld(notReady: [SimWorld::STD]);
        $plan = $world->validate([SimWorld::item('STD', '2027-03-10', 450.0)]);
        $outcome = $world->write($plan);
        self::assertSame(StopReason::NotReady, $outcome->stopReason);
        self::assertSame(['not_ready'], self::statuses($outcome));

        $world->clock->advance(60);
        $again = $world->validate([SimWorld::item('STD', '2027-03-10', 450.0)]);
        $outcome = $world->write($again, [], [SimWorld::STD]);
        self::assertNull($outcome->stopReason);
        self::assertSame(['accepted', 'stored'], self::statuses($outcome), 'the recalculation is a call with no read-back of its own');
        $attempts = $world->db->select('SELECT outcome, scope FROM wp_ritmo_api_quota_log WHERE action = ?', ['recalculation']);
        self::assertSame([['outcome' => 'ok', 'scope' => SimWorld::STD]], $attempts);
    }

    public function testWithoutAQuotaSlotTheListingGetsNoCallAtAll(): void
    {
        $world = new SimWorld(notReady: [SimWorld::STD]);
        foreach (['2027-03-02T13:00:00Z', '2027-03-03T08:00:00Z', '2027-03-03T11:00:00Z'] as $at) {
            $world->db->insert('api_quota_log', ['provider' => 'pricelabs', 'action' => 'recalculation', 'scope' => SimWorld::STD, 'succeeded' => true, 'outcome' => 'ok', 'response_id' => '', 'called_at' => (new DateTimeImmutable($at))->format('Y-m-d H:i:s')]);
        }
        $plan = $world->validate([SimWorld::item('STD', '2027-03-10', 450.0), SimWorld::item('GDN', '2027-03-11', 600.0)]);

        $outcome = $world->write($plan, [], [SimWorld::STD]);

        self::assertSame(['skipped', 'skipped', 'stored'], self::statuses($outcome));
        self::assertContains('not_confirmed_quota', $outcome->alerts);
        self::assertSame(['POST /pricelabs/v1/listings/' . SimWorld::GDN . '/overrides'], $world->apis->writeLines());
    }

    public function testABodyRefusedRunAfterRunIsQuarantined(): void
    {
        foreach ([1, 2] as $run) {
            $plan = $this->world->validate([SimWorld::item('STD', '2027-03-10', 450.0)]);
            $this->world->apis->fault('POST', '/pricelabs/v1/listings/' . SimWorld::STD . '/overrides', 400);
            self::assertSame(['rejected'], self::statuses($this->world->write($plan)), 'run ' . $run);
            $this->world->clock->advance(60);
        }
        $plan = $this->world->validate([SimWorld::item('STD', '2027-03-10', 450.0)]);

        $outcome = $this->world->write($plan);

        self::assertSame(['quarantined'], self::statuses($outcome));
        self::assertContains('write_quarantined', $outcome->alerts);
        self::assertCount(2, $this->world->apis->writeLines(), 'the third run sends nothing');
    }

    public function testAWriteTheSystemTookButDidNotStoreIsAMismatchNeverRewrittenInTheRun(): void
    {
        $plan = $this->world->validate([SimWorld::item('STD', '2027-03-10', 450.0)]);
        $this->world->apis->fault('POST', '/pricelabs/v1/listings/' . SimWorld::STD . '/overrides', 200, 'drop_silently');

        $outcome = $this->world->write($plan);

        self::assertSame(['mismatch'], self::statuses($outcome));
        self::assertContains('write_mismatch', $outcome->alerts);
        self::assertCount(1, $this->world->apis->writeLines());
    }
}
