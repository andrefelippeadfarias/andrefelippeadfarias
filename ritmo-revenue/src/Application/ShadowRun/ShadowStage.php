<?php

declare(strict_types=1);

namespace Ritmo\Application\ShadowRun;

use Ritmo\Domain\Support\Days;
use Ritmo\Infrastructure\Db\Repository\PlanItemRepository;
use Ritmo\Infrastructure\Db\Repository\RunPayloadRepository;

/**
 * Stores what the run would do, and does nothing else: one row per field an item changes (floor,
 * ceiling, minimum stay) and one per date rule it would delete first, each with the value in
 * force, the proposed value, the reason, the rule trace, the outcome of the validator and its
 * issues; then the payload and the slices of the validated plan. Nothing is sent.
 */
final class ShadowStage implements Stage
{
    public const NAME = 'shadow';

    public const DELETE_FIELD = 'delete';

    public function __construct(
        private readonly PlanItemRepository $planItems,
        private readonly RunPayloadRepository $payloads,
    ) {
    }

    public function name(): string
    {
        return self::NAME;
    }

    public function run(RunContext $context): StageResult
    {
        $entries = is_array($context->state['items'] ?? null) ? $context->state['items'] : [];
        $deletions = is_array($context->state['deletions'] ?? null) ? $context->state['deletions'] : [];
        $rows = [];
        foreach ($entries as $entry) {
            $rows = [...$rows, ...self::itemRows($context, $entry)];
        }
        foreach ($deletions as $deletion) {
            $rows[] = self::row($context, $deletion['room'], $deletion['date'], self::DELETE_FIELD, null, null, $deletion['reason_code'], [
                'would_write' => $deletion['status'] === PlanItemStatus::WOULD_WRITE,
                'reason_text' => $deletion['reason_text'],
                'issues' => $deletion['issues'],
            ], $deletion['status']);
        }
        $payload = is_array($context->state['payload'] ?? null) ? $context->state['payload'] : null;
        $slices = is_array($context->state['slices'] ?? null) ? $context->state['slices'] : [];
        $wouldWrite = count(array_filter($entries, static fn (array $e): bool => $e['outcome']['status'] === PlanItemStatus::WOULD_WRITE));

        return new StageResult(
            ['items_stored' => count($rows), PlanItemStatus::WOULD_WRITE => $wouldWrite, 'slices' => count($slices), 'writes_sent' => 0],
            [],
            function () use ($context, $rows, $payload, $slices): void {
                $this->planItems->addAll($context->runId, $rows);
                if ($payload !== null) {
                    $this->payloads->store($context->runId, $context->property->id, $payload, $slices);
                }
            },
        );
    }

    /**
     * @param array<string, mixed> $entry an item of the decide stage with its outcome
     *
     * @return list<array<string, mixed>>
     */
    private static function itemRows(RunContext $context, array $entry): array
    {
        $item = $entry['item'];
        $outcome = $entry['outcome'];
        $before = is_array($item['before'] ?? null) ? $item['before'] : [];
        $params = is_array($item['reason_params'] ?? null) ? $item['reason_params'] : [];
        $details = [
            'would_write' => $outcome['status'] === PlanItemStatus::WOULD_WRITE,
            'rule_trace' => $entry['trace'] ?? [],
            'reason_params' => array_diff_key($params, ['rule_trace' => true]),
            'relation_codes' => $params['relation_codes'] ?? [],
            'order_id' => $item['order_id'] ?? null,
            'pace_status' => $entry['pace_status'] ?? null,
            'direction' => $entry['direction'] ?? null,
            'current_price' => $before['price'] ?? null,
            'min_percent' => $outcome['min_percent'],
            'max_percent' => $outcome['max_percent'],
            'check_in' => $item['check_in'] ?? null,
            'check_out' => $item['check_out'] ?? null,
            'orphan' => $item['orphan'] ?? null,
            'issues' => $outcome['issues'],
        ];
        $rows = [];
        foreach (['floor' => ['min_price', 'min_price'], 'ceiling' => ['max_price', 'max_price'], 'min_stay' => ['min_stay', 'min_stay']] as $field => [$was, $proposed]) {
            if (($item[$proposed] ?? null) === null) {
                continue;
            }
            $current = $before[$was] ?? null;
            $rows[] = self::row($context, $item['room'], $item['date'], $field, $current === null ? null : (float) $current, (float) $item[$proposed], (string) $item['reason_code'], $details, $outcome['status']);
        }

        return $rows;
    }

    /**
     * @param array<string, mixed> $details
     *
     * @return array<string, mixed>
     */
    private static function row(RunContext $context, string $room, string $date, string $field, ?float $current, ?float $proposed, string $reason, array $details, string $status): array
    {
        return [
            'propertyId' => $context->property->id,
            'roomCode' => $room,
            'night' => Days::date($date),
            'field' => $field,
            'current' => $current,
            'proposed' => $proposed,
            'reason' => $reason,
            'details' => $details,
            'status' => $status,
        ];
    }
}
