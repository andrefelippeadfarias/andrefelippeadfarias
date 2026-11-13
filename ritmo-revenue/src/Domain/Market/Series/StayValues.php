<?php

declare(strict_types=1);

namespace Ritmo\Domain\Market\Series;

/**
 * The per-night values of one stay for one series: one value per competitor the series takes,
 * in the order the competitors first appear among the samples.
 *
 * Reference: faixas_dono.estadia_por_noite. Cheapest value = the per-night price of the cheapest
 * row; comparable value = the per-night price of a comparable row when one was measured (MK-X5),
 * else the cheapest value times the competitor's comparable factor. Unavailable rows, the
 * property's own listings, inactive competitors and competitors outside the catalogue are left
 * out. No rounding here.
 */
final class StayValues
{
    private function __construct()
    {
    }

    /**
     * @param list<StaySample>                $samples   the samples of ONE stay (newest per competitor and basis)
     * @param array<int, CompetitorProfile>   $catalogue by competitor id
     *
     * @return list<float>
     */
    public static function of(array $samples, array $catalogue, SeriesDefinition $series, float $defaultFactor, float $factorCap): array
    {
        return array_values(self::byCompetitor($samples, $catalogue, $series, $defaultFactor, $factorCap));
    }

    /**
     * The same values keyed by competitor id (the market anchor takes them per competitor).
     *
     * @param list<StaySample>              $samples
     * @param array<int, CompetitorProfile> $catalogue
     *
     * @return array<int, float>
     */
    public static function byCompetitor(array $samples, array $catalogue, SeriesDefinition $series, float $defaultFactor, float $factorCap): array
    {
        $rows = [];
        foreach ($samples as $sample) {
            if ($sample->available) {
                $rows[$sample->competitorId][$sample->basis->value] = $sample;
            }
        }

        $values = [];
        foreach ($rows as $competitorId => $byBasis) {
            $competitor = $catalogue[$competitorId] ?? null;
            if ($competitor === null || !$series->takes($competitor)) {
                continue;
            }
            $value = self::value($byBasis, $competitor, $series->basis, $defaultFactor, $factorCap);
            if ($value !== null) {
                $values[$competitorId] = $value;
            }
        }

        return $values;
    }

    /**
     * @param array<string, StaySample> $byBasis
     */
    private static function value(array $byBasis, CompetitorProfile $competitor, RoomBasis $basis, float $defaultFactor, float $factorCap): ?float
    {
        $cheapest = $byBasis[RoomBasis::Cheapest->value] ?? null;
        if ($basis === RoomBasis::Cheapest) {
            return $cheapest?->perNight();
        }
        $measured = $byBasis[RoomBasis::Comparable->value] ?? null;
        if ($measured !== null) {
            return $measured->perNight();
        }
        if ($cheapest === null) {
            return null;
        }

        return $cheapest->perNight() * ComparableFactor::of($competitor->comparableFactor, $competitor->factorSource, $defaultFactor, $factorCap);
    }
}
