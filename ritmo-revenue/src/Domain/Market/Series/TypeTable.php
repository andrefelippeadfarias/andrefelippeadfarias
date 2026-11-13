<?php

declare(strict_types=1);

namespace Ritmo\Domain\Market\Series;

use InvalidArgumentException;
use Ritmo\Domain\Support\Num;

/**
 * The typical value of a series on one kind of night: per percentile, the median within each
 * check-in month, then the median of those monthly medians, rounded half to even.
 *
 * Reference: faixas_dono.mediana_por_mes and the per-type part of faixas_dono.construir (the
 * median number of competitors rounded half to even; a small sample has fewer than
 * market.small_sample.stays stays or fewer than market.small_sample.competitors competitors).
 */
final class TypeTable
{
    private function __construct()
    {
    }

    /**
     * @param list<StaySeries> $stays one series, one type, by check-in
     * @param list<int>        $percentiles
     *
     * @throws InvalidArgumentException when there is no stay
     */
    public static function of(array $stays, array $percentiles, int $smallStays, int $smallCompetitors): TypeSummary
    {
        if ($stays === []) {
            throw new InvalidArgumentException('A type table needs at least one stay.');
        }
        $values = [];
        foreach ($percentiles as $p) {
            $values[(string) $p] = self::value($stays, (string) $p);
        }
        $months = array_values(array_unique(array_map(static fn (StaySeries $s): string => $s->checkIn->format('Y-m'), $stays)));
        sort($months);
        $competitors = Num::roundInt(Percentile::median(array_map(static fn (StaySeries $s): int => $s->n, $stays)));
        $last = $stays[count($stays) - 1];

        return new TypeSummary(
            $stays[0]->type,
            count($stays),
            $months,
            $competitors,
            $stays[0]->checkIn,
            $last->checkOut(),
            count($stays) < $smallStays || $competitors < $smallCompetitors,
            $values,
        );
    }

    /**
     * The median of the monthly medians of one percentile.
     *
     * @param list<StaySeries> $stays
     */
    public static function value(array $stays, string $percentile): int
    {
        $byMonth = [];
        foreach ($stays as $stay) {
            $byMonth[$stay->checkIn->format('Y-m')][] = $stay->percentiles[$percentile];
        }

        return Num::roundInt(Percentile::median(array_map(static fn (array $v): float => Percentile::median($v), array_values($byMonth))));
    }
}
