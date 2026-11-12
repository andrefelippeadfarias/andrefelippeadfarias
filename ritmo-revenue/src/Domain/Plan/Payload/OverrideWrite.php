<?php

declare(strict_types=1);

namespace Ritmo\Domain\Plan\Payload;

use DateTimeImmutable;
use Ritmo\Domain\Support\Days;

/**
 * One date rule as it is written to the pricing system: the floor and the ceiling as whole
 * percentages over the listing base ("percent_base": -31 is the base times 0.69), the minimum
 * stay, both arrival and departure masks when either is set, and the reason (null keeps the reason
 * of the rule in force).
 *
 * Reference: one override of validar_plano.montar_payload.
 */
final class OverrideWrite
{
    public const PERCENT_OF_BASE = 'percent_base';

    public function __construct(
        public readonly DateTimeImmutable $date,
        public readonly ?string $reason,
        public readonly ?int $minPercent,
        public readonly ?int $maxPercent,
        public readonly ?int $minStay,
        public readonly ?string $checkIn,
        public readonly ?string $checkOut,
        public readonly string $currency,
    ) {
    }

    /**
     * The override in the pricing system's fields, every value a string.
     *
     * @return array<string, string>
     */
    public function toArray(): array
    {
        $out = ['date' => Days::key($this->date)];
        if ($this->reason !== null) {
            $out['reason'] = $this->reason;
        }
        if ($this->minPercent !== null) {
            $out += ['min_price' => (string) $this->minPercent, 'min_price_type' => self::PERCENT_OF_BASE, 'currency' => $this->currency];
        }
        if ($this->maxPercent !== null) {
            $out += ['max_price' => (string) $this->maxPercent, 'max_price_type' => self::PERCENT_OF_BASE, 'currency' => $this->currency];
        }
        if ($this->minStay !== null) {
            $out['min_stay'] = (string) $this->minStay;
        }
        if ($this->checkIn !== null && $this->checkOut !== null) {
            $out += ['check_in_check_out_enabled' => '1', 'check_in' => $this->checkIn, 'check_out' => $this->checkOut];
        }

        return $out;
    }
}
