<?php

declare(strict_types=1);

namespace Ritmo\Application\Write\Quota;

use DateTimeImmutable;
use DateTimeZone;

/**
 * May a listing be recalculated now? The listing's rolling window, the account's rolling
 * minute, the account wait after any 429, and the "same run" rule: a listing whose attempt
 * failed (or got a 429) since the run started waits for the next run. A morning-slot warning
 * never blocks. Every attempt counts, failures and 429 included; a pending attempt older than the
 * call timeout counts as a failure.
 * Reference: cota_recalculo.situacao (golden cota_recalculo.situacao.json).
 */
final class RecalcQuota
{
    private const UPSTREAM_UNAVAILABLE = 'temporarily unavailable';

    public function __construct(private readonly QuotaConfig $config = new QuotaConfig(), private readonly int $pendingTimeoutSeconds = 120)
    {
    }

    /**
     * @param list<RecalcAttempt>     $attempts    every attempt of the account (any listing)
     * @param DateTimeImmutable       $runStart    start of the current run (see runStartFor())
     * @param DateTimeImmutable|null  $nextFullRun start of the next full run, for the morning-slot warning; null: no warning
     */
    public function verdict(array $attempts, string $listing, DateTimeImmutable $now, DateTimeImmutable $runStart, ?DateTimeImmutable $nextFullRun = null): QuotaVerdict
    {
        $utc = new DateTimeZone('UTC');
        $now = $now->setTimezone($utc);
        $runStart = $runStart->setTimezone($utc);
        $all = $this->upTo($attempts, $now);
        $window = array_values(array_filter($all, fn (RecalcAttempt $a): bool => $a->listing === $listing && $a->at > $this->ago($now, $this->config->windowHours * 3600)));
        $minute = array_values(array_filter($all, fn (RecalcAttempt $a): bool => $a->at > $this->ago($now, 60)));
        $last429 = $this->last(array_filter($all, fn (RecalcAttempt $a): bool => $this->outcome($a, $now) === AttemptOutcome::RateLimited && $a->at > $this->ago($now, $this->config->accountWaitAfter429Seconds)));
        $lastFailure = $this->last(array_filter($all, fn (RecalcAttempt $a): bool => $a->listing === $listing && $a->at >= $runStart && $this->failed($a, $now)));

        $roomSlot = RollingWindow::opensAt(self::instants($window), $this->config->perListing, $this->config->windowHours * 3600);
        $accountSlot = RollingWindow::opensAt(self::instants($minute), $this->config->perAccountPerMinute, 60);
        $blocked = [];
        if ($roomSlot !== null) {
            $blocked[] = QuotaVerdict::ROOM_QUOTA_SPENT;
        }
        if ($accountSlot !== null) {
            $blocked[] = QuotaVerdict::ACCOUNT_MINUTE_LIMIT;
        }
        $wait429 = null;
        if ($last429 !== null && ($last429->listing !== $listing || $last429->at < $runStart)) {
            $wait429 = $last429->at->modify(sprintf('+%d seconds', $this->config->accountWaitAfter429Seconds));
            $blocked[] = QuotaVerdict::ACCOUNT_429_WAIT;
        }
        if ($lastFailure !== null) {
            $blocked[] = $this->failureCode($lastFailure, $now);
        }

        $allowed = $blocked === [];

        return new QuotaVerdict(
            $allowed,
            $blocked,
            count($window),
            max(0, $this->config->perListing - count($window)),
            $this->byResult($window, $now),
            $roomSlot,
            $accountSlot,
            $wait429,
            count($minute),
            $allowed && $this->warnsAboutTheMorning($window, $now, $nextFullRun),
            $runStart,
        );
    }

    /**
     * The run start the "same run" rule uses: the scheduled slot that contains the run; for a run
     * started by hand or a resume, at least the configured cooldown before now.
     */
    public function runStartFor(DateTimeImmutable $slotStart, DateTimeImmutable $now, bool $scheduled): DateTimeImmutable
    {
        if ($scheduled) {
            return $slotStart;
        }

        return min($slotStart, $this->ago($now, $this->config->failureCooldownMinutes * 60));
    }

    /**
     * @param list<RecalcAttempt> $attempts
     *
     * @return list<RecalcAttempt> the attempts up to now plus the clock slack, oldest first
     */
    private function upTo(array $attempts, DateTimeImmutable $now): array
    {
        $limit = $now->modify(sprintf('+%d seconds', $this->config->futureSlackSeconds));
        $kept = array_values(array_filter($attempts, static fn (RecalcAttempt $a): bool => $a->at <= $limit));
        usort($kept, static fn (RecalcAttempt $a, RecalcAttempt $b): int => $a->at <=> $b->at);

        return $kept;
    }

    /**
     * @param array<int, RecalcAttempt> $attempts in time order
     */
    private function last(array $attempts): ?RecalcAttempt
    {
        return $attempts === [] ? null : $attempts[array_key_last($attempts)];
    }

    private function outcome(RecalcAttempt $attempt, DateTimeImmutable $now): AttemptOutcome
    {
        return $attempt->effectiveOutcome($now, $this->pendingTimeoutSeconds);
    }

    private function failed(RecalcAttempt $attempt, DateTimeImmutable $now): bool
    {
        return in_array($this->outcome($attempt, $now), [AttemptOutcome::Failure, AttemptOutcome::RateLimited], true);
    }

    private function failureCode(RecalcAttempt $failure, DateTimeImmutable $now): string
    {
        if ($this->outcome($failure, $now) === AttemptOutcome::RateLimited) {
            return QuotaVerdict::ROOM_429_THIS_RUN;
        }

        return str_contains(strtolower((string) $failure->error), self::UPSTREAM_UNAVAILABLE)
            ? QuotaVerdict::ROOM_UPSTREAM_UNAVAILABLE_THIS_RUN
            : QuotaVerdict::ROOM_FAILURE_THIS_RUN;
    }

    /**
     * The attempt now would be the last of the window and its slot would reopen only after the
     * next full run: that run would have no recalculation left.
     *
     * @param list<RecalcAttempt> $window
     */
    private function warnsAboutTheMorning(array $window, DateTimeImmutable $now, ?DateTimeImmutable $nextFullRun): bool
    {
        if ($nextFullRun === null || count($window) !== $this->config->perListing - 1) {
            return false;
        }
        $reopens = RollingWindow::opensAt([...self::instants($window), $now], $this->config->perListing, $this->config->windowHours * 3600);

        return $reopens !== null && $reopens > $nextFullRun;
    }

    /**
     * @param list<RecalcAttempt> $window
     *
     * @return array<string, int>
     */
    private function byResult(array $window, DateTimeImmutable $now): array
    {
        $counts = array_fill_keys(array_map(static fn (AttemptOutcome $o): string => $o->value, AttemptOutcome::cases()), 0);
        foreach ($window as $attempt) {
            ++$counts[$this->outcome($attempt, $now)->value];
        }

        return $counts;
    }

    /**
     * @param list<RecalcAttempt> $attempts
     *
     * @return list<DateTimeImmutable>
     */
    private static function instants(array $attempts): array
    {
        return array_map(static fn (RecalcAttempt $a): DateTimeImmutable => $a->at, $attempts);
    }

    private function ago(DateTimeImmutable $now, int $seconds): DateTimeImmutable
    {
        return $now->modify(sprintf('-%d seconds', $seconds));
    }
}
