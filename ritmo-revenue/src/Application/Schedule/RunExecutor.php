<?php

declare(strict_types=1);

namespace Ritmo\Application\Schedule;

use Closure;
use DateTimeImmutable;
use Ritmo\Domain\Model\Property;
use Ritmo\Domain\Operations\RunKind;

/**
 * The work of a run, without any lock: opening the run and working through its stages until a
 * deadline. {@see LockedRunLauncher} calls it only while it holds the property's single run lock,
 * so an implementation never takes that lock itself (the integration implements it over the run
 * pipeline, with the stages of each kind).
 *
 * The heartbeat extends the property's run lock; an implementation calls it before each stage and
 * before each write slice and stops at once when it answers false (the lock was lost: another
 * request may be working on the property).
 */
interface RunExecutor
{
    /**
     * Opens a run of this kind for the property's local day $today and works on it until the
     * deadline.
     *
     * @param bool                $forcedShadow the emergency switch is engaged: read, watch and decide, write nothing
     * @param Closure(): bool     $heartbeat
     */
    public function start(Property $property, RunKind $kind, bool $forcedShadow, DateTimeImmutable $today, DateTimeImmutable $deadline, Closure $heartbeat): LaunchResult;

    /**
     * Works on the open run $runId of the property from its next stage until the deadline. The
     * run is of the property's current local day (the launcher closed it otherwise).
     *
     * @param Closure(): bool $heartbeat
     */
    public function resume(Property $property, int $runId, DateTimeImmutable $deadline, Closure $heartbeat): LaunchResult;
}
