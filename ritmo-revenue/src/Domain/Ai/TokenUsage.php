<?php

declare(strict_types=1);

namespace Ritmo\Domain\Ai;

/**
 * The tokens one answer used, as the API reports them in "usage": input, output, cache writes
 * and cache reads. Never anything of the prompt itself.
 */
final class TokenUsage
{
    public function __construct(
        public readonly int $input = 0,
        public readonly int $output = 0,
        public readonly int $cacheWrite = 0,
        public readonly int $cacheRead = 0,
    ) {
    }

    /**
     * @param array<string, mixed> $usage the "usage" object of an answer
     */
    public static function fromApi(array $usage): self
    {
        return new self(
            self::count($usage['input_tokens'] ?? 0),
            self::count($usage['output_tokens'] ?? 0),
            self::count($usage['cache_creation_input_tokens'] ?? 0),
            self::count($usage['cache_read_input_tokens'] ?? 0),
        );
    }

    public function plus(self $other): self
    {
        return new self($this->input + $other->input, $this->output + $other->output, $this->cacheWrite + $other->cacheWrite, $this->cacheRead + $other->cacheRead);
    }

    /**
     * The cost of these tokens at the given prices, in US dollars (not rounded).
     */
    public function costUsd(ModelPrices $prices): float
    {
        return ($this->input * $prices->input + $this->output * $prices->output + $this->cacheRead * $prices->cacheRead + $this->cacheWrite * $prices->cacheWrite) / 1000000;
    }

    /**
     * @return array{input: int, output: int, cache_write: int, cache_read: int}
     */
    public function toArray(): array
    {
        return ['input' => $this->input, 'output' => $this->output, 'cache_write' => $this->cacheWrite, 'cache_read' => $this->cacheRead];
    }

    private static function count(mixed $value): int
    {
        return is_int($value) && $value > 0 ? $value : 0;
    }
}
