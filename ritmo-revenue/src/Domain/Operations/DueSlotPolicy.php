<?php

declare(strict_types=1);

namespace Ritmo\Domain\Operations;

use DateTimeImmutable;
use Ritmo\Domain\Support\Days;

/**
 * Which due slot of a property runs now, as what, and which are closed without a run.
 *
 * Rules, in order:
 * 1. A paused property starts nothing: every due slot is skipped as paused.
 * 2. A slot of a local day before today never runs (skipped_missed_day); the scheduled ones give
 *    the gap the report and the run_missed alert state.
 * 3. A plan approved by a person is written first (approved_write); the scheduled slots wait.
 * 4. A slot is covered when a run that reads at least as much started at or after it and
 *    completed (a full slot only by a full or manual run; a write run covers nothing).
 * 5. Of the remaining slots of today only the latest runs; the earlier ones are coalesced (a light
 *    run reads the movement since the last successful read, so one run covers every missed slot).
 * 6. The chosen slot runs full when it is the full slot, or when no full run completed today (the
 *    first run of a day is always full; reference SKILL.md "ou quando a data virou").
 * 7. A start more than late_tolerance_minutes after the slot is late (it still runs).
 * A slot in the future is never started.
 */
final class DueSlotPolicy
{
    public function __construct(private readonly ScheduleConfig $config)
    {
    }

    /**
     * @param list<Slot>    $due   the due slots of the property
     * @param list<PastRun> $runs  its recent runs
     * @param DateTimeImmutable $today the property's local date now (date-only)
     */
    public function choose(array $due, array $runs, DateTimeImmutable $today, DateTimeImmutable $now, bool $paused): SlotDecision
    {
        $ready = array_values(array_filter($due, static fn (Slot $s): bool => $s->at <= $now));
        usort($ready, static fn (Slot $a, Slot $b): int => $a->at <=> $b->at);
        if ($paused) {
            return self::none(array_map(static fn (Slot $s): array => [$s, SlotStatus::SkippedPaused], $ready), null);
        }

        $closures = [];
        $missed = [];
        $approved = [];
        $scheduled = [];
        foreach ($ready as $slot) {
            if (Days::key($slot->localDate) < Days::key($today)) {
                $closures[] = [$slot, SlotStatus::SkippedMissedDay];
                if ($slot->kind->isScheduled()) {
                    $missed[] = $slot->at;
                }
            } elseif ($slot->kind === SlotKind::ApprovedWrite) {
                $approved[] = $slot;
            } else {
                $scheduled[] = $slot;
            }
        }
        $gap = $missed === [] ? null : [$missed[0], $missed[count($missed) - 1]];

        if ($approved !== []) {
            return new SlotDecision($approved[0], RunKind::ApprovedWrite, false, $approved[0]->secondsUntil($now), false, $closures, $gap);
        }

        $open = [];
        foreach ($scheduled as $slot) {
            if (self::covered($slot, $runs)) {
                $closures[] = [$slot, SlotStatus::SkippedCovered];
            } else {
                $open[] = $slot;
            }
        }
        if ($open === []) {
            return self::none($closures, $gap);
        }

        $chosen = array_pop($open);
        foreach ($open as $slot) {
            $closures[] = [$slot, SlotStatus::SkippedCoalesced];
        }
        $full = $chosen->kind === SlotKind::Full || !self::fullCompleted($runs, $today);
        $delay = $chosen->secondsUntil($now);

        return new SlotDecision(
            $chosen,
            $full ? RunKind::Full : RunKind::Light,
            $full && $chosen->kind !== SlotKind::Full,
            $delay,
            $delay > $this->config->lateToleranceMinutes * 60,
            $closures,
            $gap,
        );
    }

    /**
     * The one retry of a run that failed because a system could not be reached: retry minutes
     * after the failure, and only when the next configured slot is more than twice that away.
     * Null when retries are off or the next slot comes soon enough.
     */
    public function retryAfter(DateTimeImmutable $failedAt, SlotPlanner $planner): ?Slot
    {
        $minutes = $this->config->retryFailedAfterMinutes;
        if ($minutes === 0) {
            return null;
        }
        $at = $failedAt->modify(sprintf('+%d minutes', $minutes));
        if ($planner->nextAfter($failedAt)->at->getTimestamp() - $failedAt->getTimestamp() <= 2 * $minutes * 60) {
            return null;
        }

        return new Slot(SlotKind::Retry, $planner->localDate($at), $planner->localTime($at), $at);
    }

    /**
     * @param list<PastRun> $runs
     */
    private static function covered(Slot $slot, array $runs): bool
    {
        foreach ($runs as $run) {
            if (!$run->completed || $run->startedAt < $slot->at || $run->kind === RunKind::ApprovedWrite) {
                continue;
            }
            if ($slot->kind !== SlotKind::Full || $run->kind->isFull()) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<PastRun> $runs
     */
    private static function fullCompleted(array $runs, DateTimeImmutable $today): bool
    {
        foreach ($runs as $run) {
            if ($run->completed && $run->kind->isFull() && Days::key($run->runDay) === Days::key($today)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<array{0: Slot, 1: SlotStatus}>                     $closures
     * @param array{0: DateTimeImmutable, 1: DateTimeImmutable}|null $gap
     */
    private static function none(array $closures, ?array $gap): SlotDecision
    {
        return new SlotDecision(null, null, false, 0, false, $closures, $gap);
    }
}
