<?php

declare(strict_types=1);

namespace Ritmo\Application\ShadowRun;

use Ritmo\Domain\Support\Days;
use Ritmo\Infrastructure\Db\Repository\PlanItemRepository;

/**
 * The last stage of a shadow run: it stores what the run would write, item by item, with the
 * rule trace that explains it, and marks the items that would really be written. It holds no
 * connector, so it cannot write anywhere outside the plugin's own tables.
 */
final class ShadowStage implements Stage
{
    public const NAME = 'shadow';

    public function __construct(private readonly PlanItemRepository $planItems)
    {
    }

    public function name(): string
    {
        return self::NAME;
    }

    public function run(RunContext $context): StageResult
    {
        $entries = is_array($context->state['items'] ?? null) ? $context->state['items'] : [];
        $outcomes = is_array($context->state['outcomes'] ?? null) ? $context->state['outcomes'] : [];

        $rows = [];
        foreach ($entries as $key => $entry) {
            $item = StateCodec::toPlanItem($entry['item']);
            $outcome = $outcomes[$key] ?? ['status' => PlanItemStatus::REJECTED, 'min_percent' => null, 'max_percent' => null, 'issues' => []];
            $details = [
                'would_write' => $outcome['status'] === PlanItemStatus::WOULD_WRITE,
                'rule_trace' => $entry['trace'] ?? [],
                'reason_params' => $item->reasonParams,
                'pace_status' => $entry['pace_status'] ?? null,
                'direction' => $entry['direction'] ?? null,
                'current_price' => $item->before?->price,
                'min_percent' => $outcome['min_percent'],
                'max_percent' => $outcome['max_percent'],
                'issues' => $outcome['issues'],
            ];
            foreach (['floor' => [$item->before?->minPrice, $item->minPrice], 'ceiling' => [$item->before?->maxPrice, $item->maxPrice]] as $field => [$current, $proposed]) {
                if ($proposed === null) {
                    continue;
                }
                $rows[] = [
                    'propertyId' => $context->property->id,
                    'roomCode' => $item->roomCode,
                    'night' => Days::normalize($item->date),
                    'field' => $field,
                    'current' => $current,
                    'proposed' => $proposed,
                    'reason' => $item->reasonCode,
                    'details' => $details,
                    'status' => $outcome['status'],
                ];
            }
        }

        $wouldWrite = count(array_filter($outcomes, static fn (array $o): bool => $o['status'] === PlanItemStatus::WOULD_WRITE));

        return new StageResult(
            ['items_stored' => count($rows), PlanItemStatus::WOULD_WRITE => $wouldWrite, 'writes_sent' => 0],
            [],
            function () use ($context, $rows): void {
                $this->planItems->addAll($context->runId, $rows);
            },
        );
    }
}
