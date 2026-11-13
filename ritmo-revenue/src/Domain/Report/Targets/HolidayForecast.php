<?php

declare(strict_types=1);

namespace Ritmo\Domain\Report\Targets;

use Ritmo\Domain\Support\Num;

/**
 * The revenue of a holiday per scenario: what is sold + the room nights still missing to reach
 * the target occupancy x the scenario's daily rate.
 *
 * Reference: metas_faturamento.feriados.
 */
final class HolidayForecast
{
    private function __construct()
    {
    }

    /**
     * @return array<string, int> scenario value => forecast
     */
    public static function of(HolidayTarget $holiday): array
    {
        $out = [];
        foreach (Scenario::cases() as $scenario) {
            $target = $holiday->occupancy[$scenario->value] * $holiday->roomNights;
            $missing = max(0, $target - $holiday->soldNights);
            $out[$scenario->value] = Num::roundInt($holiday->soldRevenue + $missing * $holiday->adr[$scenario->value]);
        }

        return $out;
    }
}
