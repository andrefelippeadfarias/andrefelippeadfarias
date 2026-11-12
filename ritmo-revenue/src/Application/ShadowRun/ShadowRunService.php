<?php

declare(strict_types=1);

namespace Ritmo\Application\ShadowRun;

use OutOfBoundsException;
use Ritmo\Application\EmergencySwitch;
use Ritmo\Contracts\Logger;
use Ritmo\Domain\Config\RuleSet;
use Ritmo\Domain\Model\Property;
use Ritmo\Domain\Model\RoomType;
use Ritmo\Domain\Support\Clock;
use Ritmo\Domain\Support\DateRange;
use Ritmo\Domain\Support\Days;
use Ritmo\Infrastructure\Db\Repository\PropertyRepository;
use Ritmo\Infrastructure\Db\Repository\RoomTypeRepository;
use Ritmo\Infrastructure\Db\Repository\RuleSetRepository;
use Ritmo\Infrastructure\Db\Repository\RunRepository;
use Ritmo\Infrastructure\Lock\DbLock;

/**
 * Starts or resumes the shadow run of a property: under the property's run lock, for the
 * property's "today" (from the clock, in the property's timezone), unless the emergency switch is
 * engaged (then a run left open is closed as stopped). A run left open by a request that died
 * resumes from its next stage; one left open on an earlier day is closed as failed and a new run
 * starts.
 */
final class ShadowRunService
{
    public const KIND = 'shadow';

    /** Nights the run covers, from today. */
    public const HORIZON_NIGHTS = 60;

    public const LOCK_TTL_SECONDS = 600;

    /** Time after which no new stage starts in the same request. */
    public const DEFAULT_BUDGET_SECONDS = 20;

    public function __construct(
        private readonly PropertyRepository $properties,
        private readonly RoomTypeRepository $roomTypes,
        private readonly RuleSetRepository $ruleSets,
        private readonly RunRepository $runs,
        private readonly DbLock $locks,
        private readonly EmergencySwitch $switch,
        private readonly RunPipeline $pipeline,
        private readonly Clock $clock,
        private readonly Logger $logger,
    ) {
    }

    public static function lockKey(string $propertyId): string
    {
        return 'shadow_run:' . $propertyId;
    }

    /**
     * @throws OutOfBoundsException when the property does not exist
     */
    public function run(string $propertyId, int $budgetSeconds = self::DEFAULT_BUDGET_SECONDS): RunOutcome
    {
        $property = $this->properties->find($propertyId) ?? throw new OutOfBoundsException(sprintf('Unknown property "%s".', $propertyId));
        if ($this->switch->isEngaged()) {
            return $this->blocked($property);
        }

        $lock = $this->locks->acquire(self::lockKey($property->id), self::LOCK_TTL_SECONDS);
        if ($lock === null) {
            return new RunOutcome(RunOutcome::BUSY, $this->runs->openRun($property->id, self::KIND)['id'] ?? null, '', 'Another shadow run of this property is in progress.');
        }

        try {
            $context = $this->context($property);
            if ($context instanceof RunOutcome) {
                return $context;
            }

            return $this->pipeline->continue(
                $context,
                $this->clock->now()->modify(sprintf('+%d seconds', max(0, $budgetSeconds))),
                fn (): bool => $this->locks->heartbeat($lock, self::LOCK_TTL_SECONDS),
            );
        } finally {
            $this->locks->release($lock);
        }
    }

    /**
     * Resumes the open shadow run of every property that has one (for the scheduled hook).
     *
     * @return array<string, RunOutcome> by property id
     */
    public function resumeOpenRuns(int $budgetSeconds = self::DEFAULT_BUDGET_SECONDS): array
    {
        $outcomes = [];
        foreach ($this->properties->all() as $property) {
            if ($this->runs->openRun($property->id, self::KIND) !== null) {
                $outcomes[$property->id] = $this->run($property->id, $budgetSeconds);
            }
        }

        return $outcomes;
    }

    /**
     * With the switch engaged no run starts, and a run left open is closed as stopped under the
     * lock, so it never resumes from what it collected before the emergency. When another request
     * holds the lock, that request stops the run itself before its next stage.
     */
    private function blocked(Property $property): RunOutcome
    {
        $message = 'The emergency switch is engaged: no run starts.';
        $lock = $this->locks->acquire(self::lockKey($property->id), self::LOCK_TTL_SECONDS);
        if ($lock === null) {
            return new RunOutcome(RunOutcome::BLOCKED, null, '', $message);
        }

        try {
            $open = $this->runs->openRun($property->id, self::KIND);
            if ($open === null) {
                return new RunOutcome(RunOutcome::BLOCKED, null, '', $message);
            }
            $reason = $open['stage'] === ''
                ? 'Stopped by the emergency switch before its first stage.'
                : sprintf('Stopped by the emergency switch after the stage "%s".', $open['stage']);
            $this->runs->finish($open['id'], RunRepository::STOPPED, $open['summary'], $this->clock->now(), $reason);
            $this->logger->warning('An open run was stopped by the emergency switch.', ['run_id' => $open['id'], 'property_id' => $property->id, 'stage' => $open['stage']]);

            return new RunOutcome(RunOutcome::BLOCKED, $open['id'], $open['stage'], $message . ' The open run was stopped.');
        } finally {
            $this->locks->release($lock);
        }
    }

    private function context(Property $property): RunContext|RunOutcome
    {
        $now = $this->clock->now();
        $today = $this->clock->today($property->timezone);
        $horizon = DateRange::nights($today, self::HORIZON_NIGHTS);
        $rooms = array_values(array_filter($this->roomTypes->forProperty($property->id), static fn (RoomType $room): bool => $room->pmsRef !== ''));

        $open = $this->runs->openRun($property->id, self::KIND);
        if ($open !== null && ($open['run_day'] === null || Days::key($open['run_day']) !== Days::key($today))) {
            $this->runs->finish($open['id'], RunRepository::FAILED, $open['summary'], $now, 'The day of the property changed before the run ended; a new run was started.');
            $open = null;
        }

        if ($open !== null) {
            $this->logger->info('A shadow run resumes.', ['run_id' => $open['id'], 'property_id' => $property->id, 'stage' => $open['stage']]);

            return new RunContext($open['id'], $property, $rooms, $this->rules($open['ruleset_version']), $today, $horizon, $open['stage'], $open['summary'], $this->runs->state($open['id']));
        }

        $version = $this->ruleSets->currentVersion();
        $runId = $this->runs->start($property->id, self::KIND, $property->mode, $version, $now, $today);
        $this->logger->info('A shadow run starts.', ['run_id' => $runId, 'property_id' => $property->id]);
        if ($rooms === []) {
            $error = 'No room type of the property is mapped to the PMS.';
            $this->runs->finish($runId, RunRepository::FAILED, [], $now, $error);

            return new RunOutcome(RunOutcome::FAILED, $runId, '', $error);
        }

        return new RunContext($runId, $property, $rooms, $this->rules($version), $today, $horizon);
    }

    private function rules(?int $version): RuleSet
    {
        return ($version === null ? null : $this->ruleSets->find($version)) ?? RuleSet::defaults();
    }
}
