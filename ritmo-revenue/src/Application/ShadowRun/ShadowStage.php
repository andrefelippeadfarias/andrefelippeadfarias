<?php

declare(strict_types=1);

namespace Ritmo\Application\ShadowRun;

use Ritmo\Domain\Model\DateOverride;
use Ritmo\Domain\Pricing\RuleInForce;
use Ritmo\Domain\Support\Days;
use Ritmo\Infrastructure\Db\Repository\PlanItemRepository;
use Ritmo\Infrastructure\Db\Repository\RunPayloadRepository;

/**
 * Stores what the run would do, and does nothing else: one row per field an item changes (floor,
 * ceiling, minimum stay) and one per date rule it would delete first, each with the value in
 * force, the proposed value, the reason, the rule trace, the outcome of the validator and its
 * issues; then the payload and the slices of the validated plan. Nothing is sent. The floor and
 * ceiling in force are those the engine read; when it read none (its items carry only the price),
 * they come from the date rule the pricing system holds for the night, so the screens compare
 * against what is really there.
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
        $inForce = self::inForce($context);
        foreach ($entries as $entry) {
            $rows = [...$rows, ...self::itemRows($context, $entry, $inForce)];
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
     * The floor and ceiling of the date rule in force of each room night, as amounts over the
     * listing base read in this run.
     *
     * @return array<string, array{min_price: float|null, max_price: float|null, min_stay: int|null}> "room|YYYY-MM-DD" => amounts
     */
    private static function inForce(RunContext $context): array
    {
        $bases = [];
        foreach ($context->rooms as $room) {
            $bases[$room->code] = $room->base;
        }
        $out = [];
        foreach ($context->overrides() as $room => $nights) {
            foreach ($nights as $night => $rule) {
                if ($rule instanceof DateOverride && isset($bases[$room])) {
                    $out[$room . '|' . $night] = ['min_price' => RuleInForce::floor($rule, $bases[$room]), 'max_price' => RuleInForce::ceiling($rule, $bases[$room]), 'min_stay' => $rule->minStay];
                }
            }
        }

        return $out;
    }

    /**
     * @param array<string, mixed>                                                 $entry   an item of the decide stage with its outcome
     * @param array<string, array{min_price: float|null, max_price: float|null, min_stay: int|null}> $inForce the date rules in force (inForce())
     *
     * @return list<array<string, mixed>>
     */
    private static function itemRows(RunContext $context, array $entry, array $inForce): array
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
            $current = $before[$was] ?? ($inForce[$item['room'] . '|' . $item['date']][$was] ?? null);
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
