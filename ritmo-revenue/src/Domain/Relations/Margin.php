<?php

declare(strict_types=1);

namespace Ritmo\Domain\Relations;

use Ritmo\Domain\Relations\Config\Reader;

/**
 * How far above a reference a price must be: a percent of it or a fixed amount.
 */
final class Margin
{
    public const PERCENT = 'percent';
    public const AMOUNT = 'amount';

    private const MIN_PERCENT = -90.0;
    private const MAX_PERCENT = 500.0;
    private const MAX_AMOUNT = 100000.0;

    private function __construct(
        public readonly bool $isPercent,
        public readonly float $value,
    ) {
    }

    public static function none(): self
    {
        return new self(true, 0.0);
    }

    public static function percent(float $value): self
    {
        return new self(true, $value);
    }

    public static function amount(float $value): self
    {
        return new self(false, $value);
    }

    public static function read(Reader $reader): self
    {
        $reader->rejectUnknown(['type', 'value']);
        $type = $reader->text('type', self::PERCENT, '/^(percent|amount)$/');
        if ($type === self::PERCENT) {
            return new self(true, $reader->number('value', 0.0, self::MIN_PERCENT, self::MAX_PERCENT));
        }

        return new self(false, $reader->number('value', 0.0, -self::MAX_AMOUNT, self::MAX_AMOUNT));
    }

    /**
     * The required price for a reference price (the order of operations of the reference engine).
     */
    public function applyTo(float $reference): float
    {
        return $this->isPercent ? $reference * (1 + $this->value / 100) : $reference + $this->value;
    }

    /**
     * @return array{type: string, value: float}
     */
    public function toArray(): array
    {
        return ['type' => $this->isPercent ? self::PERCENT : self::AMOUNT, 'value' => $this->value];
    }
}
