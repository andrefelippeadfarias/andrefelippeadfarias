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

    /** Room nights listed under "what would be written"; the total is given apart. */
    public const WOULD_WRITE_SHOWN = 60;

    /** The fields of a room night the grid shows (a delete-first row goes with its night's rewrite). */
    private const FIELDS = ['floor', 'ceiling', 'min_stay'];

    public function __construct(
        private readonly PropertyRepository $properties,
        private readonly OccupancyQuery $occupancy,
        private readonly RunRepository $runs,
        private readonly PlanItemRepository $planItems,
        private readonly AlertRepository $alerts,
    ) {
    }

    /**
     * "would_write" lists the first room nights (by night, then room) the last completed shadow
     * run would write, each with its floor and ceiling (in force and proposed) and the rule trace;
     * "would_write_total" counts them all.
     *
     * @return array{property: array<string, mixed>, occupancy: array<string, mixed>, alerts: list<array<string, mixed>>, last_run: array<string, mixed>|null, last_completed: array<string, mixed>|null, push: array<string, array<string, mixed>>, plan_issues: list<string>, would_write: list<array<string, mixed>>, would_write_total: int, refused: list<array<string, mixed>>}
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
            'plan_issues' => is_array($completed['summary']['validate']['plan_issues'] ?? null) ? $completed['summary']['validate']['plan_issues'] : [],
        ] + $this->wouldWrite($completed === null ? null : $completed['id']);
    }

    /**
     * The room nights of the last completed run, by night then room: "would_write" (first
     * WOULD_WRITE_SHOWN) and "refused" (every night the validator refused, with its errors), each
     * with the price in force, the proposed floor, ceiling and stay, the reason code, the owner
     * order and relations behind it, the rule trace and the validator's issues.
     *
     * @return array{would_write: list<array<string, mixed>>, would_write_total: int, refused: list<array<string, mixed>>}
     */
    private function wouldWrite(?int $runId): array
    {
        $nights = [];
        foreach ($runId === null ? [] : $this->planItems->forRun($runId) as $item) {
            if (!in_array($item['status'], [PlanItemStatus::WOULD_WRITE, PlanItemStatus::REJECTED], true) || !in_array($item['field'], self::FIELDS, true)) {
                continue;
            }
            $key = $item['night']->format('Y-m-d') . '|' . $item['room_code'];
            $details = $item['details'];
            $nights[$key] ??= [
                'room_code' => $item['room_code'],
                'night' => $item['night'],
                'status' => $item['status'],
                'current_price' => $details['current_price'] ?? null,
                'floor' => null,
                'ceiling' => null,
                'min_stay' => null,
                'reason' => $item['reason'],
                'order_id' => $details['order_id'] ?? null,
                'relation_codes' => $details['relation_codes'] ?? [],
                'rule_trace' => $details['rule_trace'] ?? [],
                'issues' => $details['issues'] ?? [],
            ];
            $nights[$key][$item['field']] = [
                'current' => $item['current'],
                'proposed' => $item['proposed'],
                'percent' => match ($item['field']) {
                    'floor' => $details['min_percent'] ?? null,
                    'ceiling' => $details['max_percent'] ?? null,
                    default => null,
                },
            ];
        }
        ksort($nights);
        $write = array_values(array_filter($nights, static fn (array $n): bool => $n['status'] === PlanItemStatus::WOULD_WRITE));

        return [
            'would_write' => array_slice($write, 0, self::WOULD_WRITE_SHOWN),
            'would_write_total' => count($write),
            'refused' => array_values(array_filter($nights, static fn (array $n): bool => $n['status'] === PlanItemStatus::REJECTED)),
        ];
    }
}
