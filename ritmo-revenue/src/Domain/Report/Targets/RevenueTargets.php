<?php

declare(strict_types=1);

namespace Ritmo\Domain\Report\Targets;

use Ritmo\Domain\Report\Month\Period;
use Ritmo\Domain\Support\Num;

/**
 * Per month and property: today's forecast (base index, realistic growth), the realistic forecast
 * (goal index, realistic growth), the maximum (the property's maximum index, realistic growth),
 * the owner's minimum (typed for the month, else the season's), the RevPAR of the minimum and the
 * minimum against the realistic forecast.
 *
 * Reference: metas_faturamento.por_pousada.
 */
final class RevenueTargets
{
    private function __construct()
    {
    }

    /**
     * @param list<string>                               $months
     * @param array<string, int>                         $units  property id => units
     * @param array<string, array<string, TargetInputs>> $inputs month => property id => inputs
     *
     * @return list<PropertyTarget>
     */
    public static function of(array $months, array $units, array $inputs, ForecastConfig $config): array
    {
        $out = [];
        foreach ($months as $month) {
            $period = Period::of($month, 'UTC');
            foreach ($units as $propertyId => $count) {
                $out[] = self::target($period, (string) $propertyId, $count, $inputs[$month][$propertyId] ?? new TargetInputs(), $config);
            }
        }

        return $out;
    }

    private static function target(Period $period, string $propertyId, int $units, TargetInputs $in, ForecastConfig $config): PropertyTarget
    {
        $growth = $config->realisticGrowth;
        $forecast = static fn (float $index): ?int => $in->hasMarket() ? Num::roundInt($in->value($growth, $index, $units, $period->days)) : null;
        $realistic = $forecast($config->index(Scenario::Goal, $propertyId));
        $minimum = $in->minimum ?? $config->seasonMinimum($period->number(), $propertyId);
        $roomNights = $units * $period->days;

        return new PropertyTarget(
            $period->key,
            $propertyId,
            $config->isHigh($period->number()),
            $forecast($config->index(Scenario::Base, $propertyId)),
            $realistic,
            $forecast($config->maximumIndex($propertyId)),
            $minimum,
            $minimum !== null && $roomNights > 0 ? Num::roundInt($minimum / $roomNights) : null,
            $minimum !== null && $realistic !== null && $realistic > 0 ? Num::roundHalfEven($minimum / $realistic - 1, 2) : null,
        );
    }
}
