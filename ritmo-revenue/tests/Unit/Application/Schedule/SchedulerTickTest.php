<?php

declare(strict_types=1);

namespace Ritmo\Tests\Unit\Application\Schedule;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Ritmo\Application\Schedule\SchedulerTick;
use Ritmo\Application\Schedule\TickReport;
use Ritmo\Domain\Operations\LocalTime;
use Ritmo\Domain\Operations\RunKind;
use Ritmo\Domain\Operations\ScheduleConfig;
use Ritmo\Domain\Operations\Slot;
use Ritmo\Domain\Operations\SlotKind;
use Ritmo\Domain\Support\Days;

/**
 * The scheduler with a fake clock (operations spec 10.2, S1 to S19; O1 to O9). The property is
 * in America/Sao_Paulo (UTC-3) with the full run at 07:30 and light runs at 12:30 and 18:30:
 * 10:30Z, 15:30Z and 21:30Z.
 */
final class SchedulerTickTest extends TestCase
{
    public function testS1TheFullSlotStartsWithItsDelay(): void
    {
        $world = new ScheduleWorld('2026-11-12T10:31:00Z');

        self::assertSame(['inn' => TickReport::STARTED], $world->tick()->properties);
        self::assertSame(['full'], $world->launcher->kinds());
        $slot = $world->slot('2026-11-12T07:30|full');
        self::assertSame(['completed', 60, false], [$slot?->status->value, $slot?->delaySeconds, $slot?->promoted]);
        self::assertSame(['2026-11-12T07:30|full' => 'completed'], $world->slotStatuses(), 'a new property starts with the slots of its day');
        self::assertNotNull($world->store->get(SchedulerTick::HEARTBEAT_KEY));
    }

    public function testS2LaterTicksStartNothingMore(): void
    {
        $world = new ScheduleWorld('2026-11-12T10:31:00Z');
        $world->tick();
        $world->at('2026-11-12T10:36:00Z');
        self::assertSame(['inn' => TickReport::IDLE], $world->tick()->properties);
        $world->at('2026-11-12T10:41:00Z');
        $world->tick();

        self::assertSame(['full'], $world->launcher->kinds());
    }

    public function testS3ALightSlotRunsLightAtItsExactTime(): void
    {
        $world = new ScheduleWorld('2026-11-12T10:31:00Z');
        $world->tick();
        $world->at('2026-11-12T15:30:00Z');
        $world->tick();

        self::assertSame(['full', 'light'], $world->launcher->kinds());
    }

    public function testS4AfterADownTheLastSlotRunsFullAndLate(): void
    {
        $world = new ScheduleWorld('2026-11-12T10:00:00Z');
        $world->tick();
        $world->at('2026-11-12T22:05:00Z');
        $world->tick();

        self::assertSame(['full'], $world->launcher->kinds());
        self::assertSame([
            '2026-11-12T07:30|full' => 'skipped_coalesced',
            '2026-11-12T12:30|light' => 'skipped_coalesced',
            '2026-11-12T18:30|light' => 'completed',
        ], $world->slotStatuses());
        self::assertTrue($world->slot('2026-11-12T18:30|light')?->promoted);
        self::assertSame(['run_late:inn'], $world->openAlerts('run_late'));

        $world->at('2026-11-13T10:31:00Z');
        $world->tick();
        self::assertSame([], $world->openAlerts('run_late'), 'a start within the tolerance resolves it');
    }

    public function testS5DaysWithoutTicksAreMissedOnceAndTheNewDayStartsFull(): void
    {
        $world = new ScheduleWorld('2026-11-10T10:31:00Z');
        $world->tick();
        $world->at('2026-11-12T12:00:00Z');
        $world->tick();

        $statuses = $world->slotStatuses();
        self::assertSame('completed', $statuses['2026-11-12T07:30|full']);
        self::assertCount(5, array_filter($statuses, static fn (string $s): bool => $s === 'skipped_missed_day'));
        self::assertCount(1, $world->openAlerts('run_missed'));
        self::assertSame(['full', 'full'], $world->launcher->kinds());
    }

    public function testS6AnOpenRunIsContinuedFirstAndTheWaitingSlotRunsAfterIt(): void
    {
        $world = new ScheduleWorld('2026-11-12T10:31:00Z');
        $world->launcher->next = ['running'];
        $world->tick();
        $world->launcher->continueAs = 'running';
        $world->at('2026-11-12T15:31:00Z');

        self::assertSame(['inn' => TickReport::CONTINUED], $world->tick()->properties);
        self::assertSame('due', $world->slotStatuses()['2026-11-12T12:30|light']);
        $world->launcher->continueAs = 'complete';
        $world->at('2026-11-12T15:36:00Z');
        $world->tick();
        self::assertSame('completed', $world->slotStatuses()['2026-11-12T07:30|full'], 'O8: the run resumed and its slot closed');
        $world->at('2026-11-12T15:41:00Z');
        $world->tick();

        self::assertSame(['full', 'light'], $world->launcher->kinds(), 'the full run started before 12:30, so the light slot is not covered');
        self::assertSame('completed', $world->slotStatuses()['2026-11-12T12:30|light']);
    }

    public function testASlotWhoseRunEndedElsewhereIsClosedWithTheRunsStatus(): void
    {
        $world = new ScheduleWorld('2026-11-12T10:31:00Z');
        $world->launcher->next = ['running'];
        $world->tick();
        $runId = $world->slot('2026-11-12T07:30|full')?->runId ?? 0;
        $world->runs->finish($runId, 'stopped', [], $world->clock->now(), 'Stopped by a person.');
        $world->at('2026-11-12T10:36:00Z');
        $world->tick();

        self::assertSame('stopped', $world->slotStatuses()['2026-11-12T07:30|full']);
        self::assertSame('Stopped by a person.', $world->slot('2026-11-12T07:30|full')?->note);
    }

    public function testS7ATickWhileAnotherWorksDoesNothing(): void
    {
        $world = new ScheduleWorld('2026-11-12T10:31:00Z');
        $held = $world->locks->acquire(SchedulerTick::TICK_LOCK, 100);

        self::assertTrue($world->tick()->busy);
        self::assertSame([], $world->launcher->kinds());

        $world->clock->advance(101);
        self::assertFalse($world->tick()->busy, 'S8: a lock whose holder died expires');
        self::assertNotNull($held);
    }

    public function testABusyPropertyKeepsTheSlotDue(): void
    {
        $world = new ScheduleWorld('2026-11-12T10:31:00Z');
        $world->launcher->next = ['busy'];

        self::assertSame(['inn' => TickReport::WAITING], $world->tick()->properties);
        self::assertSame('due', $world->slotStatuses()['2026-11-12T07:30|full']);
        $world->at('2026-11-12T10:36:00Z');
        $world->tick();
        self::assertSame('completed', $world->slotStatuses()['2026-11-12T07:30|full']);
    }

    public function testS9ARunOfAnEarlierDayIsClosedAsFailedAndNeverRetried(): void
    {
        $world = new ScheduleWorld('2026-11-12T21:30:00Z', ['full_time' => '07:30', 'light_times' => ['18:30']]);
        $world->launcher->next = ['complete', 'running'];
        $world->tick();
        $world->at('2026-11-13T02:58:00Z');
        $world->schedule = ScheduleConfig::fromArray(['full_time' => '07:30', 'light_times' => ['18:30', '23:58']]);
        $world->tick();
        $world->launcher->continueAs = 'day_changed';
        $world->at('2026-11-13T03:03:00Z');
        $world->tick();

        self::assertSame('failed', $world->slotStatuses()['2026-11-12T23:58|light']);
        self::assertSame([], array_filter(array_keys($world->slotStatuses()), static fn (string $k): bool => str_ends_with($k, '|retry')));
        $world->at('2026-11-13T10:31:00Z');
        $world->tick();
        self::assertSame(RunKind::Full, $world->launcher->started[2][0], 'the first run of the new day is full');
    }

    public function testS10APausedPropertySkipsItsSlotsAndResumesAtTheEndOfThePause(): void
    {
        $world = new ScheduleWorld('2026-11-12T10:31:00Z');
        $world->tick();
        $world->states->pause('inn', new DateTimeImmutable('2026-11-12T18:00:00Z'), 7, 'Maintenance');
        $world->at('2026-11-12T15:31:00Z');
        $world->tick();
        self::assertSame('skipped_paused', $world->slotStatuses()['2026-11-12T12:30|light']);

        $world->at('2026-11-12T18:05:00Z');
        $world->tick();
        self::assertFalse($world->states->get('inn')->paused);
        $world->at('2026-11-12T21:31:00Z');
        $world->tick();
        self::assertSame(['full', 'light'], $world->launcher->kinds());
    }

    public function testAPauseStopsTheRunInProgressAtItsNextStage(): void
    {
        $world = new ScheduleWorld('2026-11-12T10:31:00Z');
        $world->launcher->next = ['running'];
        $world->tick();
        $world->states->pause('inn', null, 7, 'Maintenance');
        $world->at('2026-11-12T10:36:00Z');

        self::assertSame(['inn' => TickReport::CONTINUED], $world->tick()->properties);
        self::assertSame('stopped', $world->slotStatuses()['2026-11-12T07:30|full']);
        self::assertSame('The property is paused.', $world->slot('2026-11-12T07:30|full')?->note);
        self::assertSame(0, $world->launcher->continued);
    }

    public function testS12UnderTheEmergencySwitchRunsAreForcedShadowOrSkipped(): void
    {
        $world = new ScheduleWorld('2026-11-12T10:31:00Z');
        $world->switch->engage();
        $world->tick();
        self::assertSame([[RunKind::Full, true]], $world->launcher->started);

        $world->schedule = ScheduleConfig::fromArray(['full_time' => '07:30', 'light_times' => ['12:30', '18:30'], 'stop_reads_under_emergency' => true]);
        $world->at('2026-11-12T15:31:00Z');
        self::assertSame(['inn' => TickReport::BLOCKED], $world->tick()->properties);
        self::assertSame('skipped_blocked', $world->slotStatuses()['2026-11-12T12:30|light']);
        self::assertCount(1, $world->launcher->started);
    }

    public function testS13AConnectorFailureGetsOneRetry(): void
    {
        $world = new ScheduleWorld('2026-11-12T10:31:00Z');
        $world->tick();
        $world->launcher->next = ['connector', 'connector'];
        $world->at('2026-11-12T15:31:00Z');
        $world->tick();
        self::assertSame('failed', $world->slotStatuses()['2026-11-12T12:30|light']);
        self::assertSame('due', $world->slotStatuses()['2026-11-12T12:46|retry']);

        $world->at('2026-11-12T15:47:00Z');
        $world->tick();
        self::assertSame(['full', 'light', 'light'], $world->launcher->kinds());
        self::assertSame('failed', $world->slotStatuses()['2026-11-12T12:46|retry']);
        self::assertCount(1, array_filter(array_keys($world->slotStatuses()), static fn (string $k): bool => str_ends_with($k, '|retry')), 'a failed retry is not retried');
    }

    public function testS14TheRepeatedHourWhenTheClocksGoBackRunsOnce(): void
    {
        $world = new ScheduleWorld('2026-10-25T00:31:00Z', ['full_time' => '01:30'], 'Europe/Lisbon');
        $world->tick();
        $world->at('2026-10-25T01:31:00Z');
        $world->tick();

        self::assertSame(['2026-10-25T01:30|full' => 'completed'], $world->slotStatuses());
        self::assertCount(1, $world->launcher->started);
    }

    public function testS15ATimeInTheSpringForwardGapRunsAtTheEndOfTheGap(): void
    {
        $world = new ScheduleWorld('2026-03-08T07:05:00Z', ['full_time' => '02:30'], 'America/New_York');
        $world->tick();

        self::assertSame(['full'], $world->launcher->kinds());
        self::assertSame(300, $world->slot('2026-03-08T02:30|full')?->delaySeconds, 'the slot fell on 03:00 local');
    }

    public function testS16ALightTimeBeforeTheFullTimeRunsFullAndTheFullSlotStillRuns(): void
    {
        $world = new ScheduleWorld('2026-11-12T09:01:00Z', ['full_time' => '07:30', 'light_times' => ['06:00']]);
        $world->tick();
        $world->at('2026-11-12T10:31:00Z');
        $world->tick();

        self::assertSame(['full', 'full'], $world->launcher->kinds());
        self::assertTrue($world->slot('2026-11-12T06:00|light')?->promoted);
    }

    public function testS18AnApprovedWriteWaitsForTheLockAndRunsFirst(): void
    {
        $world = new ScheduleWorld('2026-11-12T10:31:00Z');
        $world->tick();
        $now = new DateTimeImmutable('2026-11-12T17:10:00Z');
        $world->at('2026-11-12T17:10:00Z');
        $world->slots->materialise('inn', new Slot(SlotKind::ApprovedWrite, Days::date('2026-11-12'), LocalTime::fromString('14:10'), $now));
        $world->launcher->next = ['busy'];
        $world->tick();
        self::assertSame('due', $world->slotStatuses()['2026-11-12T14:10|approved_write']);

        $world->at('2026-11-12T17:15:00Z');
        $world->tick();
        self::assertSame(['full', 'approved_write'], $world->launcher->kinds());
        self::assertSame('due', $world->slotStatuses()['2026-11-12T12:30|light'], 'the scheduled slot waits for the next tick');
    }

    public function testS19AClockMovedBackNeverRunsASlotTwice(): void
    {
        $world = new ScheduleWorld('2026-11-12T10:31:00Z');
        $world->tick();
        $world->at('2026-11-12T09:35:00Z');
        $world->tick();
        $world->at('2026-11-12T10:36:00Z');
        $world->tick();

        self::assertSame(['full'], $world->launcher->kinds());
    }

    public function testAnExceptionOfARunFailsItsSlotAndIsLoggedWithItsClassOnly(): void
    {
        $world = new ScheduleWorld('2026-11-12T10:31:00Z');
        $world->launcher->next = ['throw'];
        $world->tick();

        self::assertSame('failed', $world->slotStatuses()['2026-11-12T07:30|full']);
        self::assertSame('RuntimeException', $world->slot('2026-11-12T07:30|full')?->note);
        self::assertStringNotContainsString('The run broke', $world->logger->dump());
    }
}
