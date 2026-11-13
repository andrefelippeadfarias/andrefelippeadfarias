<?php

declare(strict_types=1);

namespace Ritmo\Application\Grid;

use Ritmo\Infrastructure\Db\Repository\PlanItemRepository;
use Ritmo\Infrastructure\Db\Repository\RunRepository;

/**
 * The plan of the newest completed run of a property that decided prices (shadow, full, light
 * or manual run; an undo or an approved write only replays an earlier plan), with that run.
 */
final class LastPlan
{
    private const DECIDING_KINDS = ['shadow', 'full', 'light', 'manual', 'retry'];

    /** Completed runs looked at before giving up: a light run without movement plans nothing. */
    private const CANDIDATES = 2;

    public function __construct(private readonly RunRepository $runs, private readonly PlanItemRepository $items)
    {
    }

    /**
     * @param list<array<string, mixed>> $recent the property's latest runs (RunRepository::latest), newest first
     *
     * @return array{run: array<string, mixed>|null, plan: PlanIndex}
     */
    public function of(array $recent): array
    {
        $tried = 0;
        foreach ($recent as $run) {
            if ($run['status'] !== RunRepository::COMPLETED || !in_array($run['kind'], self::DECIDING_KINDS, true)) {
                continue;
            }
            $plan = new PlanIndex($this->items->forRun((int) $run['id']), (int) $run['id']);
            if (!$plan->isEmpty()) {
                return ['run' => $run, 'plan' => $plan];
            }
            if (++$tried >= self::CANDIDATES) {
                break;
            }
        }

        return ['run' => null, 'plan' => PlanIndex::empty()];
    }

    /**
     * The plan of one run, empty when it planned nothing (a shadow run without market prices).
     *
     * @param array<string, mixed> $run a row of RunRepository::latest
     */
    public function ofRun(array $run): PlanIndex
    {
        return new PlanIndex($this->items->forRun((int) $run['id']), (int) $run['id']);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function recentRuns(string $propertyId, int $limit = 20): array
    {
        return $this->runs->latest($propertyId, $limit);
    }
}
