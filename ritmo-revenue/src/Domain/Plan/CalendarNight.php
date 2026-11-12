<?php

declare(strict_types=1);

namespace Ritmo\Domain\Plan;

use InvalidArgumentException;

/**
 * One room night of the fresh pricing calendar the validator reads: its price, the floor in force
 * and the units sold of the total.
 *
 * Reference: validar_plano.precos_da_coleta (preco, piso, vendidas, total, livres).
 */
final class CalendarNight
{
    /**
     * @throws InvalidArgumentException on negative units or more sold than the total
     */
    public function __construct(
        public readonly ?float $price,
        public readonly ?float $floor,
        public readonly int $sold,
        public readonly int $total,
    ) {
        if ($sold < 0 || $total < 0) {
            throw new InvalidArgumentException('Units cannot be negative.');
        }
    }

    /**
     * Units still for sale; under zero when overbooked.
     */
    public function free(): int
    {
        return $this->total - $this->sold;
    }
}
