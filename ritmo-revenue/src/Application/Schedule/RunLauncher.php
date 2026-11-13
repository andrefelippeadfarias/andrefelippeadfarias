<?php

declare(strict_types=1);

namespace Ritmo\Application\Schedule;

use Ritmo\Domain\Model\Property;
use Ritmo\Domain\Operations\RunKind;

/**
 * Starts and continues the runs of a property for the scheduler. The implementation (the run
 * service of the integration) takes the property's single run lock (one lock for every kind of
 * run, O1) and answers busy when another run holds it; it closes an open run of an earlier local
 * day as failed, and never writes under the emergency switch.
 */
interface RunLauncher
{
    /**
     * Continues the run in progress of the property, of any kind.
     *
     * @return LaunchResult|null null when the property has no run in progress
     */
    public function continueOpen(Property $property, int $budgetSeconds): ?LaunchResult;

    /**
     * Stops the run in progress of a paused property at its stage boundary (never inside a write
     * slice: a write stage stops before its next slice and leaves its groups to the recovery).
     *
     * @return LaunchResult|null null when the property has no run in progress
     */
    public function stopOpen(Property $property, string $reason): ?LaunchResult;

    /**
     * Starts a run.
     *
     * @param bool $forcedShadow the emergency switch is engaged: read, watch and decide, write nothing
     */
    public function start(Property $property, RunKind $kind, bool $forcedShadow, int $budgetSeconds): LaunchResult;
}
