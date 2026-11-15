<?php

declare(strict_types=1);

namespace Ritmo\Application\Grid;

use Ritmo\Infrastructure\Db\Repository\PlanItemRepository;
use Ritmo\Infrastructure\Db\Repository\RunRepository;

/**
 * The plan of the newest completed run of a property that decided prices (shadow, full, light
 * or manual run; an undo or an approved write only replays an earlier plan), with that run, and
 * the newest completed deciding run even when it planned nothing ("latest": a run without market
 * prices reads the calendars but prices no night), so a screen never says "no run yet" after one.
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
     * @return array{run: array<string, mixed>|null, plan: PlanIndex, latest: array<string, mixed>|null}
     */
    public function of(array $recent): array
    {
        $tried = 0;
        $latest = null;
        foreach ($recent as $run) {
            if ($run['status'] !== RunRepository::COMPLETED || !in_array($run['kind'], self::DECIDING_KINDS, true)) {
                continue;
            }
            $latest ??= $run;
            $plan = new PlanIndex($this->items->forRun((int) $run['id']), (int) $run['id']);
            if (!$plan->isEmpty()) {
                return ['run' => $run, 'plan' => $plan, 'latest' => $latest];
            }
            if (++$tried >= self::CANDIDATES) {
                break;
            }
        }

        return ['run' => null, 'plan' => PlanIndex::empty(), 'latest' => $latest];
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
