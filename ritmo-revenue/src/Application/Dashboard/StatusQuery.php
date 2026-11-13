<?php

declare(strict_types=1);

namespace Ritmo\Application\Dashboard;

use OutOfBoundsException;
use Ritmo\Application\EmergencySwitch;
use Ritmo\Domain\Support\Clock;
use Ritmo\Infrastructure\Db\Repository\PlanApprovalRepository;
use Ritmo\Infrastructure\Db\Repository\PropertyRepository;
use Ritmo\Infrastructure\Db\Repository\RunRepository;
use Ritmo\Infrastructure\Schedule\OperationStateRepository;

/**
 * Reads the status strip of a property from stored data (five statements, no external call).
 */
final class StatusQuery
{
    public function __construct(
        private readonly PropertyRepository $properties,
        private readonly EmergencySwitch $switch,
        private readonly OperationStateRepository $states,
        private readonly PlanApprovalRepository $requests,
        private readonly RunRepository $runs,
        private readonly Clock $clock,
    ) {
    }

    /**
     * @throws OutOfBoundsException when the property does not exist
     */
    public function forProperty(string $propertyId): StatusStrip
    {
        $property = $this->properties->find($propertyId) ?? throw new OutOfBoundsException(sprintf('Unknown property "%s".', $propertyId));
        $now = $this->clock->now();
        $state = $this->states->get($property->id);
        $waiting = array_values(array_filter($this->requests->pending($property->id), static fn (array $r): bool => $r['expires_at'] > $now));
        $expires = $waiting === [] ? null : min(array_map(static fn (array $r) => $r['expires_at'], $waiting));
        $last = $this->runs->latest($property->id, 1)[0] ?? null;

        return new StatusStrip(
            $property->id,
            $property->mode->value,
            $this->switch->isEngaged(),
            $state->isPausedAt($now),
            $state->isPausedAt($now) ? $state->pausedUntil : null,
            count($waiting),
            array_sum(array_map(static fn (array $r): int => count($r['entries']), $waiting)),
            $expires,
            $last === null ? null : ['id' => $last['id'], 'kind' => $last['kind'], 'status' => $last['status'], 'started_at' => $last['started_at'], 'finished_at' => $last['finished_at']],
        );
    }

    /**
     * Entries waiting in every property: the counter of the Approvals menu entry.
     */
    public function waitingEverywhere(): int
    {
        $now = $this->clock->now();
        $count = 0;
        foreach ($this->properties->all() as $property) {
            foreach ($this->requests->pending($property->id) as $request) {
                $count += $request['expires_at'] > $now ? count($request['entries']) : 0;
            }
        }

        return $count;
    }
}
