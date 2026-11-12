<?php

declare(strict_types=1);

namespace Ritmo\Application\Dashboard;

use OutOfBoundsException;
use Ritmo\Application\ShadowRun\PlanItemStatus;
use Ritmo\Application\ShadowRun\ShadowRunService;
use Ritmo\Infrastructure\Db\Repository\AlertRepository;
use Ritmo\Infrastructure\Db\Repository\PlanItemRepository;
use Ritmo\Infrastructure\Db\Repository\PropertyRepository;
use Ritmo\Infrastructure\Db\Repository\RunRepository;

/**
 * Everything the read-only dashboard shows for one property, from the plugin's own tables:
 * occupancy against the goals, open alerts, the push state and the result of the last shadow run
 * with the list of what it would write.
 */
final class PropertyDashboard
{
    private const RECENT_RUNS = 20;

    private const ALERTS = 20;

    public function __construct(
        private readonly PropertyRepository $properties,
        private readonly OccupancyQuery $occupancy,
        private readonly RunRepository $runs,
        private readonly PlanItemRepository $planItems,
        private readonly AlertRepository $alerts,
    ) {
    }

    /**
     * @return array{property: array<string, mixed>, occupancy: array<string, mixed>, alerts: list<array<string, mixed>>, last_run: array<string, mixed>|null, last_completed: array<string, mixed>|null, push: array<string, array<string, mixed>>, would_write: list<array<string, mixed>>}
     *
     * @throws OutOfBoundsException when the property does not exist
     */
    public function build(string $propertyId): array
    {
        $property = $this->properties->find($propertyId) ?? throw new OutOfBoundsException(sprintf('Unknown property "%s".', $propertyId));
        $shadowRuns = array_values(array_filter(
            $this->runs->latest($property->id, self::RECENT_RUNS),
            static fn (array $run): bool => $run['kind'] === ShadowRunService::KIND
        ));
        $completed = null;
        foreach ($shadowRuns as $run) {
            if ($run['status'] === RunRepository::COMPLETED) {
                $completed = $run;
                break;
            }
        }

        return [
            'property' => $property->toArray(),
            'occupancy' => $this->occupancy->forProperty($property->id),
            'alerts' => $this->alerts->unresolved($property->id, self::ALERTS),
            'last_run' => $shadowRuns[0] ?? null,
            'last_completed' => $completed,
            'push' => is_array($completed['summary']['measure']['push'] ?? null) ? $completed['summary']['measure']['push'] : [],
            'would_write' => $completed === null ? [] : array_values(array_filter(
                $this->planItems->forRun($completed['id']),
                static fn (array $item): bool => $item['status'] === PlanItemStatus::WOULD_WRITE
            )),
        ];
    }
}
