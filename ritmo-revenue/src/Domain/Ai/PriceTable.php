<?php

declare(strict_types=1);

namespace Ritmo\Domain\Ai;

use InvalidArgumentException;

/**
 * The price of each model the assistant may be answered by, per million tokens in US dollars.
 *
 * Defaults (list prices when the plugin was written): Opus 5.5 4 / 20 (cache reads 0.20),
 * Sonnet 5.5 2 / 10 (cache reads 0.10), Haiku 5.5 0.10 / 0.50; a cache write costs 1.25 times
 * the input. "other" prices any other model, such as the model a refusal fell back to; it is set
 * high on purpose, so the estimate errs on the side of caution.
 */
final class PriceTable
{
    public const OTHER = 'other';

    private const DEFAULTS = [
        AiModel::OPUS => ['input' => 4.0, 'output' => 20.0, 'cache_read' => 0.2, 'cache_write' => 5.0],
        AiModel::SONNET => ['input' => 2.0, 'output' => 10.0, 'cache_read' => 0.1, 'cache_write' => 2.5],
        AiModel::HAIKU => ['input' => 0.1, 'output' => 0.5, 'cache_read' => 0.01, 'cache_write' => 0.125],
        self::OTHER => ['input' => 5.0, 'output' => 25.0, 'cache_read' => 0.5, 'cache_write' => 6.25],
    ];

    /**
     * @param array<string, ModelPrices> $rows model id (or "other") => prices
     */
    private function __construct(private readonly array $rows)
    {
    }

    public static function defaults(): self
    {
        return self::fromArray([]);
    }

    /**
     * The table from stored rows; a model left out keeps its default.
     *
     * @param array<string, mixed> $data model id => {input, output, cache_read, cache_write}
     *
     * @throws InvalidArgumentException on an unknown row or a price that does not read
     */
    public static function fromArray(array $data): self
    {
        $rows = [];
        foreach (self::DEFAULTS as $model => $prices) {
            $rows[$model] = ModelPrices::fromArray($prices);
        }
        foreach ($data as $model => $prices) {
            if (!isset(self::DEFAULTS[$model])) {
                throw new InvalidArgumentException(sprintf('"%s" is not a row of the price table.', (string) $model));
            }
            if (!is_array($prices)) {
                throw new InvalidArgumentException('Each row of the price table holds four prices.');
            }
            $rows[$model] = ModelPrices::fromArray($prices);
        }

        return new self($rows);
    }

    /**
     * The prices of a model: its own row, else the "other" row.
     */
    public function forModel(string $model): ModelPrices
    {
        return $this->rows[$model] ?? $this->rows[self::OTHER];
    }

    /**
     * @return array<string, array{input: float, output: float, cache_read: float, cache_write: float}>
     */
    public function toArray(): array
    {
        return array_map(static fn (ModelPrices $p): array => $p->toArray(), $this->rows);
    }
}
