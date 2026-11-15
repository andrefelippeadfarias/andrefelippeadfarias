<?php

declare(strict_types=1);

namespace Ritmo\Domain\Ai;

use InvalidArgumentException;

/**
 * What one model costs, in US dollars per million tokens: input, output, cache reads and cache
 * writes. The customer may change the table (prices move); the plugin only estimates.
 */
final class ModelPrices
{
    public const MAX = 1000.0;

    public function __construct(
        public readonly float $input,
        public readonly float $output,
        public readonly float $cacheRead,
        public readonly float $cacheWrite,
    ) {
        foreach ([$input, $output, $cacheRead, $cacheWrite] as $price) {
            if (!is_finite($price) || $price < 0 || $price > self::MAX) {
                throw new InvalidArgumentException(sprintf('A price per million tokens is between 0 and %d dollars.', (int) self::MAX));
            }
        }
    }

    /**
     * @param array<string, mixed> $data input, output, cache_read, cache_write
     *
     * @throws InvalidArgumentException on a missing or out-of-range price
     */
    public static function fromArray(array $data): self
    {
        $prices = [];
        foreach (['input', 'output', 'cache_read', 'cache_write'] as $key) {
            $value = $data[$key] ?? null;
            if (!is_int($value) && !is_float($value)) {
                throw new InvalidArgumentException(sprintf('The price "%s" must be a number.', $key));
            }
            $prices[] = (float) $value;
        }

        return new self(...$prices);
    }

    /**
     * @return array{input: float, output: float, cache_read: float, cache_write: float}
     */
    public function toArray(): array
    {
        return ['input' => $this->input, 'output' => $this->output, 'cache_read' => $this->cacheRead, 'cache_write' => $this->cacheWrite];
    }
}
