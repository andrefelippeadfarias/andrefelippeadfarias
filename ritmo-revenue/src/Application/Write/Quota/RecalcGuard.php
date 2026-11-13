<?php

declare(strict_types=1);

namespace Ritmo\Application\Write\Quota;

use Closure;
use DateTimeImmutable;
use Ritmo\Contracts\Exception\ConnectorException;
use Ritmo\Contracts\Exception\RateLimited;
use Ritmo\Domain\Support\Clock;
use Ritmo\Domain\Write\QuotaConfig;
use Ritmo\Infrastructure\Db\Repository\RecalcAttemptRepository;

/**
 * The quota around every recalculation call: the verdict is asked before the call (a refusal
 * means no call at all, never a wait loop), the attempt is recorded as pending BEFORE the call
 * and settled after it (ok, failure or rate_limited), so neither a failure nor a crash is
 * forgotten.
 */
final class RecalcGuard
{
    private readonly RecalcQuota $quota;

    private readonly AttemptLog $log;

    public function __construct(
        private readonly RecalcAttemptRepository $attempts,
        private readonly Clock $clock,
        private readonly string $provider,
        private readonly QuotaConfig $config = new QuotaConfig(),
        int $callTimeoutSeconds = 120,
    ) {
        $this->quota = new RecalcQuota($config, $callTimeoutSeconds);
        $this->log = new AttemptLog($config->futureSlackSeconds);
    }

    public function verdict(string $listing, DateTimeImmutable $runStart, ?DateTimeImmutable $nextFullRun = null): QuotaVerdict
    {
        $now = $this->clock->now();

        return $this->quota->verdict($this->recorded($now), $listing, $now, $runStart, $nextFullRun);
    }

    /**
     * The start of the "same run" for a run of a scheduled slot, or of one started otherwise.
     */
    public function runStart(DateTimeImmutable $slotStart, bool $scheduled): DateTimeImmutable
    {
        return $this->quota->runStartFor($slotStart, $this->clock->now(), $scheduled);
    }

    /**
     * Records the attempt, makes the call and settles the attempt with its outcome.
     *
     * @param Closure(): void $call the recalculation call
     *
     * @throws ConnectorException the call's failure, after it was recorded
     */
    public function attempt(string $listing, Closure $call): void
    {
        $now = $this->clock->now();
        $attempt = $this->log->accept($this->recorded($now), new RecalcAttempt($listing, $now, AttemptOutcome::Pending), $now);
        if ($attempt === null) {
            throw new AttemptRefused('An attempt of this listing was already recorded this second.');
        }
        $id = $this->attempts->record($this->provider, $listing, AttemptOutcome::Pending->value, $attempt->at);
        try {
            $call();
        } catch (RateLimited $limited) {
            $this->attempts->settle($id, AttemptOutcome::RateLimited->value, $limited->getMessage());

            throw $limited;
        } catch (ConnectorException $failure) {
            $this->attempts->settle($id, AttemptOutcome::Failure->value, $failure->getMessage());

            throw $failure;
        }
        $this->attempts->settle($id, AttemptOutcome::Ok->value);
    }

    /**
     * @return list<RecalcAttempt>
     */
    private function recorded(DateTimeImmutable $now): array
    {
        $since = $now->modify(sprintf('-%d seconds', $this->config->windowHours * 3600 + $this->config->futureSlackSeconds));

        return array_map(
            static fn (array $row): RecalcAttempt => new RecalcAttempt($row['listing'], $row['at'], AttemptOutcome::from($row['outcome']), $row['error'], $row['response_id'] === '' ? null : $row['response_id'], $row['id']),
            $this->attempts->since($this->provider, $since)
        );
    }
}
