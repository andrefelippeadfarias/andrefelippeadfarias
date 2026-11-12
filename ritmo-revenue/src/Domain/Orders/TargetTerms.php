<?php

declare(strict_types=1);

namespace Ritmo\Domain\Orders;

/**
 * How a target order steers the pace step of a weak night: the sales ramp, the free step, the
 * strong-night thresholds, the fallback floor and the gradual descent. Read by the pace step of
 * the plan assembly; amounts are in the sent plan.
 *
 * Reference: pace.py lines 585-671 and 885-1065 (escada_vendas, passo_livre, so_desce,
 * ocupacao_max, frente_ocupacao, topo_enviados, piso_atras, gatilho_dias, gatilho_min_dias,
 * degraus_atras, degraus_dias).
 */
final class TargetTerms
{
    /**
     * @param float|null                $strongOccupancy      null = orders.defaults.strong_night_occupancy
     * @param float|null                $strongAheadOccupancy null = orders.defaults.strong_night_ahead_occupancy
     * @param array<string, float>      $top                  room code => highest sent price the ramp aims at
     * @param array<string, float>      $fallbackFloors       room code => floor of an unsold night close to the date
     * @param int|null                  $fallbackTriggerDays  null = orders.defaults.fallback_trigger_days
     * @param int|null                  $fallbackMinDays      days between the order date and the trigger, or null
     * @param array<string, list<float>> $descentFloors       room code => floors of the descent steps, highest first
     * @param list<int>|null            $descentMarks         null = orders.defaults.descent_marks
     */
    public function __construct(
        public readonly bool $salesRamp = false,
        public readonly bool $freeStep = false,
        public readonly bool $onlyDown = false,
        public readonly ?float $strongOccupancy = null,
        public readonly ?float $strongAheadOccupancy = null,
        public readonly array $top = [],
        public readonly array $fallbackFloors = [],
        public readonly ?int $fallbackTriggerDays = null,
        public readonly ?int $fallbackMinDays = null,
        public readonly array $descentFloors = [],
        public readonly ?array $descentMarks = null,
    ) {
    }
}
