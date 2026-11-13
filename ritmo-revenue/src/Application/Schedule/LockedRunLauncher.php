<?php

declare(strict_types=1);

namespace Ritmo\Application\Schedule;

use Closure;
use DateTimeImmutable;
use Ritmo\Application\ShadowRun\ShadowRunService;
use Ritmo\Contracts\Logger;
use Ritmo\Domain\Model\Property;
use Ritmo\Domain\Operations\RunKind;
use Ritmo\Domain\Support\Clock;
use Ritmo\Domain\Support\Days;
use Ritmo\Infrastructure\Db\Repository\RunRepository;
use Ritmo\Infrastructure\Lock\DbLock;
use Ritmo\Infrastructure\Lock\LockHandle;

/**
 * The single run lock of a property (operations spec 3.4, O1): every kind of run (scheduled full
 * and light runs, "Run now", the write of an approved plan) starts, continues and stops only under
 * the lock "property_run:<id>", so two runs of one property never overlap. A request that finds
 * the lock held answers busy and never queues a second run; a lock whose holder died expires
 * after its TTL and the next request resumes the open run from its next stage, never from the
 * start. Between two requests the open run itself excludes a new one: a run starts only when the
 * property has none in progress, of any kind.
 *
 * A run of an earlier local day is never continued: it is closed as failed and its plan is never
 * written (the day of a run is fixed when it starts).
 */
final class LockedRunLauncher implements RunLauncher
{
    public const LOCK_TTL_SECONDS = 600;

    private const LOCK_PREFIX = 'property_run:';

    public function __construct(
        private readonly RunRepository $runs,
        private readonly DbLock $locks,
        private readonly RunExecutor $executor,
        private readonly Clock $clock,
        private readonly Logger $logger,
    ) {
    }

    public static function lockKey(string $propertyId): string
    {
        return self::LOCK_PREFIX . $propertyId;
    }

    public function continueOpen(Property $property, int $budgetSeconds): ?LaunchResult
    {
        return $this->locked($property, function (LockHandle $lock) use ($property, $budgetSeconds): ?LaunchResult {
            $open = $this->openRun($property->id);
            if ($open === null) {
                return null;
            }
            $today = $this->clock->today($property->timezone);
            if ($open['run_day'] === null || Days::key($open['run_day']) !== Days::key($today)) {
                return $this->closeDayChanged($property, $open['id'], $open['summary']);
            }

            return $this->executor->resume($property, $open['id'], $this->deadline($budgetSeconds), $this->heartbeat($lock));
        });
    }

    public function stopOpen(Property $property, string $reason): ?LaunchResult
    {
        return $this->locked($property, function () use ($property, $reason): ?LaunchResult {
            $open = $this->openRun($property->id);
            if ($open === null) {
                return null;
            }
            $this->runs->finish($open['id'], RunRepository::STOPPED, $open['summary'], $this->clock->now(), $reason);
            $this->logger->info('An open run was stopped at its stage boundary.', ['run_id' => $open['id'], 'property_id' => $property->id, 'stage' => $open['stage']]);

            return new LaunchResult(LaunchStatus::Stopped, $open['id'], null, $reason);
        });
    }

    public function start(Property $property, RunKind $kind, bool $forcedShadow, int $budgetSeconds): LaunchResult
    {
        return $this->locked($property, function (LockHandle $lock) use ($property, $kind, $forcedShadow, $budgetSeconds): LaunchResult {
            if ($this->openRun($property->id) !== null) {
                return LaunchResult::busy();
            }

            return $this->executor->start(
                $property,
                $kind,
                $forcedShadow,
                $this->clock->today($property->timezone),
                $this->deadline($budgetSeconds),
                $this->heartbeat($lock),
            );
        }) ?? LaunchResult::busy();
    }

    /**
     * Runs $work under the property's run lock; busy when another request holds it.
     *
     * @param callable(LockHandle): (LaunchResult|null) $work
     */
    private function locked(Property $property, callable $work): ?LaunchResult
    {
        $lock = $this->locks->acquire(self::lockKey($property->id), self::LOCK_TTL_SECONDS);
        if ($lock === null) {
            return LaunchResult::busy();
        }

        try {
            return $work($lock);
        } finally {
            $this->locks->release($lock);
        }
    }

    /**
     * @param array<string, mixed> $summary what the run's completed stages found (kept)
     */
    private function closeDayChanged(Property $property, int $runId, array $summary): LaunchResult
    {
        $note = 'The day of the property changed before the run ended; its plan is never written.';
        $this->runs->finish($runId, RunRepository::FAILED, $summary, $this->clock->now(), $note);
        $this->logger->warning('A run of an earlier day was closed as failed.', ['run_id' => $runId, 'property_id' => $property->id]);

        return new LaunchResult(LaunchStatus::Failed, $runId, FailureKind::DayChanged, $note);
    }

    /**
     * The open run of the property, of any kind (the shadow kind of "Run now" included).
     *
     * @return array{id: int, run_day: DateTimeImmutable|null, summary: array<string, mixed>, stage: string}|null
     */
    private function openRun(string $propertyId): ?array
    {
        $kinds = [...array_map(static fn (RunKind $kind): string => $kind->value, RunKind::cases()), ShadowRunService::KIND];
        $open = null;
        foreach ($kinds as $kind) {
            $run = $this->runs->openRun($propertyId, $kind);
            if ($run !== null && ($open === null || $run['started_at'] > $open['started_at'])) {
                $open = $run;
            }
        }

        return $open;
    }

    private function deadline(int $budgetSeconds): DateTimeImmutable
    {
        return $this->clock->now()->modify(sprintf('+%d seconds', max(1, $budgetSeconds)));
    }

    /**
     * @return Closure(): bool
     */
    private function heartbeat(LockHandle $lock): Closure
    {
        return fn (): bool => $this->locks->heartbeat($lock, self::LOCK_TTL_SECONDS);
    }
}
