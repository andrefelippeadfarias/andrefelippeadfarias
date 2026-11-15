<?php

declare(strict_types=1);

namespace Ritmo\Domain\Ai;

/**
 * What the assistant used in one calendar month: the calls (without any content: when, what for,
 * which model answered, the tokens, the estimated cost, whether the fallback answered, how it
 * stopped) and the totals. The cost is an estimate from the configurable price table; the
 * provider's invoice is the reference.
 */
final class MonthlyUsage
{
    public const KEEP_CALLS = 200;

    /**
     * @param list<array{at: string, purpose: string, model: string, tokens: array{input: int, output: int, cache_write: int, cache_read: int}, cost_usd: float, fallback: bool, stop: string}> $calls newest last
     * @param array{calls: int, input: int, output: int, cache_write: int, cache_read: int, cost_usd: float, fallbacks: int, refusals: int} $totals
     */
    public function __construct(
        public readonly string $month,
        public readonly array $calls = [],
        public readonly array $totals = ['calls' => 0, 'input' => 0, 'output' => 0, 'cache_write' => 0, 'cache_read' => 0, 'cost_usd' => 0.0, 'fallbacks' => 0, 'refusals' => 0],
    ) {
    }

    /**
     * This month with one more call (the oldest calls beyond KEEP_CALLS are dropped from the list;
     * the totals keep them).
     */
    public function with(string $at, AiPurpose $purpose, AiReply $reply, PriceTable $prices): self
    {
        $cost = $reply->usage->costUsd($prices->forModel($reply->model));
        $calls = [...$this->calls, [
            'at' => $at,
            'purpose' => $purpose->value,
            'model' => AiModel::isWellFormed($reply->model) ? $reply->model : 'unknown',
            'tokens' => $reply->usage->toArray(),
            'cost_usd' => $cost,
            'fallback' => $reply->fallbackUsed,
            'stop' => preg_match('/^[a-z_]{1,32}$/', $reply->stopReason) === 1 ? $reply->stopReason : 'other',
        ]];
        $totals = $this->totals;
        $totals['calls'] += 1;
        foreach ($reply->usage->toArray() as $kind => $count) {
            $totals[$kind] += $count;
        }
        $totals['cost_usd'] += $cost;
        $totals['fallbacks'] += $reply->fallbackUsed ? 1 : 0;
        $totals['refusals'] += $reply->stopReason === AiReply::STOP_REFUSAL ? 1 : 0;

        return new self($this->month, array_slice($calls, -self::KEEP_CALLS), $totals);
    }

    public function spentUsd(): float
    {
        return (float) $this->totals['cost_usd'];
    }

    public function capReached(float $capUsd): bool
    {
        return $this->spentUsd() >= $capUsd;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return ['month' => $this->month, 'calls' => $this->calls, 'totals' => $this->totals];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(string $month, array $data): self
    {
        if (($data['month'] ?? null) !== $month) {
            return new self($month);
        }
        $empty = new self($month);
        $totals = $empty->totals;
        foreach ($totals as $key => $zero) {
            $value = is_array($data['totals'] ?? null) ? ($data['totals'][$key] ?? $zero) : $zero;
            $totals[$key] = is_float($zero) ? (float) (is_numeric($value) ? $value : 0) : (is_int($value) ? $value : 0);
        }
        $calls = array_values(array_filter(is_array($data['calls'] ?? null) ? $data['calls'] : [], static fn (mixed $c): bool => is_array($c) && is_string($c['at'] ?? null)));

        return new self($month, array_slice($calls, -self::KEEP_CALLS), $totals);
    }
}
