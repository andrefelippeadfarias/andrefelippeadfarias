<?php

declare(strict_types=1);

namespace Ritmo\Tests\Unit\Domain\Operations;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Ritmo\Domain\Operations\DueSlotPolicy;
use Ritmo\Domain\Operations\PastRun;
use Ritmo\Domain\Operations\RunKind;
use Ritmo\Domain\Operations\ScheduleConfig;
use Ritmo\Domain\Operations\Slot;
use Ritmo\Domain\Operations\SlotDecision;
use Ritmo\Domain\Operations\SlotKind;
use Ritmo\Domain\Operations\SlotPlanner;
use Ritmo\Domain\Operations\SlotStatus;
use Ritmo\Domain\Support\Days;

/**
 * The choice among due slots (operations spec 3.5), in America/Sao_Paulo (UTC-3), with the
 * schedule 07:30 full and 12:30, 18:30 light.
 */
final class DueSlotPolicyTest extends TestCase
{
    private const TZ = 'America/Sao_Paulo';

    private ScheduleConfig $config;

    private SlotPlanner $planner;

    private DueSlotPolicy $policy;

    protected function setUp(): void
    {
        $this->config = ScheduleConfig::fromArray(['full_time' => '07:30', 'light_times' => ['12:30', '18:30']]);
        $this->planner = new SlotPlanner($this->config, self::TZ);
        $this->policy = new DueSlotPolicy($this->config);
    }

    /**
     * @return list<Slot>
     */
    private function slots(string $fromUtc, string $toUtc): array
    {
        return $this->planner->slotsBetween(new DateTimeImmutable($fromUtc), new DateTimeImmutable($toUtc));
    }

    private function choose(array $due, array $runs, string $nowUtc, bool $paused = false): SlotDecision
    {
        $now = new DateTimeImmutable($nowUtc);

        return $this->policy->choose($due, $runs, Days::localDate($now, self::TZ), $now, $paused);
    }

    /**
     * @return array<string, string> local key => status of each closure
     */
    private static function closures(SlotDecision $decision): array
    {
        $out = [];
        foreach ($decision->closures as [$slot, $status]) {
            $out[$slot->localKey()] = $status->value;
        }

        return $out;
    }

    private static function past(RunKind $kind, string $startedUtc, string $day, bool $completed = true): PastRun
    {
        return new PastRun($kind, new DateTimeImmutable($startedUtc), Days::date($day), $completed);
    }

    public function testTheFullSlotRunsFullWithItsDelay(): void
    {
        $decision = $this->choose($this->slots('2026-11-12T10:00:00Z', '2026-11-12T10:31:00Z'), [], '2026-11-12T10:31:00Z');

        self::assertSame('2026-11-12T07:30', $decision->run?->localKey());
        self::assertSame(RunKind::Full, $decision->runKind);
        self::assertFalse($decision->promoted);
        self::assertSame(60, $decision->delaySeconds);
        self::assertFalse($decision->late);
    }

    public function testAfterADownTheEarlierSlotsAreCoalescedAndTheLastRunsFullWhenNoFullRunCompletedToday(): void
    {
        // S4: no tick from 07:00 to 19:00 local; tick at 19:05 local.
        $decision = $this->choose($this->slots('2026-11-12T10:00:00Z', '2026-11-12T22:05:00Z'), [], '2026-11-12T22:05:00Z');

        self::assertSame('2026-11-12T18:30', $decision->run?->localKey());
        self::assertSame(RunKind::Full, $decision->runKind);
        self::assertTrue($decision->promoted);
        self::assertTrue($decision->late, '35 minutes is over the 30 of tolerance');
        self::assertSame(['2026-11-12T07:30' => 'skipped_coalesced', '2026-11-12T12:30' => 'skipped_coalesced'], self::closures($decision));
    }

    public function testALightSlotRunsLightOnceAFullRunCompletedToday(): void
    {
        $runs = [self::past(RunKind::Full, '2026-11-12T10:31:00Z', '2026-11-12')];
        $decision = $this->choose($this->slots('2026-11-12T15:00:00Z', '2026-11-12T15:30:00Z'), $runs, '2026-11-12T15:30:00Z');

        self::assertSame(RunKind::Light, $decision->runKind, 'S3: the inclusive bound sees the slot at 12:30:00');
        self::assertFalse($decision->promoted);
    }

    public function testAFailedFullRunDoesNotCountAndAManualRunDoes(): void
    {
        $due = $this->slots('2026-11-12T15:00:00Z', '2026-11-12T15:30:00Z');

        self::assertSame(RunKind::Full, $this->choose($due, [self::past(RunKind::Full, '2026-11-12T10:31:00Z', '2026-11-12', false)], '2026-11-12T15:30:00Z')->runKind);
        self::assertSame(RunKind::Light, $this->choose($due, [self::past(RunKind::Manual, '2026-11-12T11:00:00Z', '2026-11-12')], '2026-11-12T15:30:00Z')->runKind);
        self::assertSame(RunKind::Full, $this->choose($due, [self::past(RunKind::Full, '2026-11-11T10:31:00Z', '2026-11-11')], '2026-11-12T15:30:00Z')->runKind, 'yesterday\'s full run does not count (O2)');
    }

    public function testASlotCoveredByALaterCompletedRunIsSkipped(): void
    {
        // S6: the 12:30 slot stayed due while a manual full run started at 12:40 and completed.
        $due = $this->slots('2026-11-12T15:00:00Z', '2026-11-12T15:30:00Z');
        $decision = $this->choose($due, [self::past(RunKind::Manual, '2026-11-12T15:40:00Z', '2026-11-12')], '2026-11-12T15:50:00Z');

        self::assertNull($decision->run);
        self::assertSame(['2026-11-12T12:30' => 'skipped_covered'], self::closures($decision));
    }

    public function testAFullSlotIsCoveredOnlyByAFullRunAndAWriteRunCoversNothing(): void
    {
        $due = $this->slots('2026-11-12T10:00:00Z', '2026-11-12T10:31:00Z');

        $light = $this->choose($due, [self::past(RunKind::Light, '2026-11-12T10:35:00Z', '2026-11-12')], '2026-11-12T10:40:00Z');
        self::assertSame('2026-11-12T07:30', $light->run?->localKey());
        $write = $this->choose($due, [self::past(RunKind::ApprovedWrite, '2026-11-12T10:35:00Z', '2026-11-12')], '2026-11-12T10:40:00Z');
        self::assertSame('2026-11-12T07:30', $write->run?->localKey());
    }

    public function testSlotsOfPastDaysAreMissedAndGiveTheGap(): void
    {
        // S5: down two days; tick at 09:00 local of the third day.
        $decision = $this->choose($this->slots('2026-11-10T09:00:00Z', '2026-11-12T12:00:00Z'), [], '2026-11-12T12:00:00Z');

        self::assertSame('2026-11-12T07:30', $decision->run?->localKey());
        self::assertSame(RunKind::Full, $decision->runKind);
        self::assertCount(6, array_filter(self::closures($decision), static fn (string $s): bool => $s === 'skipped_missed_day'));
        self::assertSame(['2026-11-10T10:30:00Z', '2026-11-11T21:30:00Z'], array_map(static fn (DateTimeImmutable $d): string => $d->format('Y-m-d\TH:i:s\Z'), $decision->missedGap ?? []));
    }

    public function testAPausedPropertyStartsNothing(): void
    {
        $decision = $this->choose($this->slots('2026-11-11T09:00:00Z', '2026-11-12T15:31:00Z'), [], '2026-11-12T15:31:00Z', true);

        self::assertNull($decision->run);
        self::assertSame(['skipped_paused'], array_values(array_unique(self::closures($decision))));
        self::assertNull($decision->missedGap, 'a pause is not a missed run');
    }

    public function testAnApprovedWriteRunsFirstAndTheScheduledSlotsWait(): void
    {
        $now = new DateTimeImmutable('2026-11-12T17:10:00Z');
        $write = new Slot(SlotKind::ApprovedWrite, Days::date('2026-11-12'), $this->planner->localTime($now), $now);
        $decision = $this->choose([...$this->slots('2026-11-12T15:00:00Z', '2026-11-12T17:10:00Z'), $write], [], '2026-11-12T17:10:00Z');

        self::assertSame(RunKind::ApprovedWrite, $decision->runKind);
        self::assertSame([], self::closures($decision));
    }

    public function testASlotInTheFutureIsNeverStarted(): void
    {
        $decision = $this->choose($this->slots('2026-11-12T10:00:00Z', '2026-11-12T16:00:00Z'), [], '2026-11-12T10:00:00Z');

        self::assertNull($decision->run);
        self::assertSame([], $decision->closures);
    }

    public function testARetryComesAfterItsMinutesOnlyWhenTheNextSlotIsFarEnough(): void
    {
        // S13: failed at 12:31 local, next slot 18:30: a retry at 12:46.
        $retry = $this->policy->retryAfter(new DateTimeImmutable('2026-11-12T15:31:00Z'), $this->planner);
        self::assertSame(SlotKind::Retry, $retry?->kind);
        self::assertSame('2026-11-12T12:46', $retry->localKey());

        self::assertNull($this->policy->retryAfter(new DateTimeImmutable('2026-11-12T21:05:00Z'), $this->planner), 'the 18:30 slot is 25 minutes away');
        $off = new DueSlotPolicy(ScheduleConfig::fromArray(['retry_failed_after_minutes' => 0]));
        self::assertNull($off->retryAfter(new DateTimeImmutable('2026-11-12T15:31:00Z'), $this->planner));
    }

    public function testEveryClosureIsAFinalStatus(): void
    {
        $decision = $this->choose($this->slots('2026-11-10T09:00:00Z', '2026-11-12T22:05:00Z'), [], '2026-11-12T22:05:00Z');

        foreach ($decision->closures as [, $status]) {
            self::assertTrue($status->isFinal());
        }
        self::assertFalse(SlotStatus::Due->isFinal());
    }
}
