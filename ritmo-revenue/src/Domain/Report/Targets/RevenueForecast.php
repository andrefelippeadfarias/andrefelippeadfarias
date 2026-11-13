<?php

declare(strict_types=1);

namespace Ritmo\Domain\Report\Targets;

use Ritmo\Domain\Report\Month\Period;
use Ritmo\Domain\Support\Num;

/**
 * The site's forecast per month and scenario:
 *   sum over properties of (market occupancy LY % / 100 x market ADR LY x growth x index x units
 *   x days x occupancy cap) + the typed adjustment, never below the revenue on the books.
 *
 * Reference: metas_faturamento.meses (divergence RP-X4: the reference's hand-coded months become
 * typed adjustments).
 */
final class RevenueForecast
{
    private function __construct()
    {
    }

    /**
     * @param list<string>                              $months   "YYYY-MM"
     * @param array<string, int>                        $units    property id => units, in display order
     * @param array<string, array<string, TargetInputs>> $inputs  month => property id => inputs
     * @param array<string, float>                      $onBooks  month => revenue on the books
     *
     * @return list<MonthForecast>
     */
    public static function months(array $months, array $units, array $inputs, array $onBooks, ForecastConfig $config): array
    {
        $out = [];
        foreach ($months as $month) {
            $days = Period::of($month, 'UTC')->days;
            $books = $onBooks[$month] ?? 0.0;
            $missing = [];
            $scenarios = [];
            foreach (Scenario::cases() as $scenario) {
                $total = 0.0;
                $adjust = 0.0;
                foreach ($units as $propertyId => $count) {
                    $in = $inputs[$month][$propertyId] ?? null;
                    if ($in === null || !$in->hasMarket()) {
                        $missing[(string) $propertyId] = true;
                        continue;
                    }
                    $total += $in->value($config->growth($scenario), $config->index($scenario, (string) $propertyId), $count, $days);
                    $adjust += $in->adjust($scenario);
                }
                $scenarios[$scenario->value] = Num::roundInt(max($total + $adjust, $books));
            }
            $out[] = new MonthForecast($month, $books, $scenarios, array_keys($missing));
        }

        return $out;
    }
}
