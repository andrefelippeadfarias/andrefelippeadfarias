<?php

declare(strict_types=1);

namespace Ritmo\Domain\Market\Series;

use DateTimeImmutable;
use Ritmo\Domain\Support\Days;
use Ritmo\Domain\Support\Num;

/**
 * The value of a series on one night, from the measured stays of the night's kind.
 *
 * Reference: faixas_dono.regua_noite, used for every series (MK-X2):
 *  1. stays starting on the night with at least market.night_min_competitors values;
 *  2. else the stays that include the night;
 *  3. else the nearest stays within market.neighbour_days (only those at the smallest distance);
 *  4. else the type table of the kind of night;
 *  5. no stay of that kind at all: no value (a gap of the ruler).
 * Each percentile is the median over the stays used, rounded half to even.
 */
final class NightSeries
{
    public function __construct(private readonly int $nightMinCompetitors, private readonly int $neighbourDays)
    {
    }

    /**
     * @param list<StaySeries> $sameType the stays of one series and of the night's kind, by check-in
     */
    public function of(DateTimeImmutable $night, array $sameType, ?TypeSummary $table): ?NightValue
    {
        $night = Days::normalize($night);
        $starting = array_values(array_filter($sameType, fn (StaySeries $s): bool => $s->checkIn == $night && $s->n >= $this->nightMinCompetitors));
        if ($starting !== []) {
            return self::median($starting, NightValue::NIGHT, null);
        }
        $containing = array_values(array_filter($sameType, static fn (StaySeries $s): bool => $s->contains($night)));
        if ($containing !== []) {
            return self::median($containing, NightValue::CONTAINING, null);
        }
        $near = array_values(array_filter($sameType, fn (StaySeries $s): bool => $s->distanceTo($night) <= $this->neighbourDays));
        if ($near !== []) {
            $closest = min(array_map(static fn (StaySeries $s): int => $s->distanceTo($night), $near));

            return self::median(array_values(array_filter($near, static fn (StaySeries $s): bool => $s->distanceTo($night) === $closest)), NightValue::NEIGHBOUR, $closest);
        }

        return $table === null ? null : new NightValue($table->values, NightValue::TYPE_TABLE, null, $table->stays, $table->competitors, null, null);
    }

    /**
     * @param non-empty-list<StaySeries> $stays
     */
    private static function median(array $stays, string $origin, ?int $distance): NightValue
    {
        $values = [];
        foreach (array_keys($stays[0]->percentiles) as $p) {
            $values[(string) $p] = Num::roundInt(Percentile::median(array_map(static fn (StaySeries $s): int => $s->percentiles[(string) $p], $stays)));
        }

        return new NightValue(
            $values,
            $origin,
            $distance,
            count($stays),
            Num::roundInt(Percentile::median(array_map(static fn (StaySeries $s): int => $s->n, $stays))),
            min(array_map(static fn (StaySeries $s): DateTimeImmutable => $s->oldestCapture, $stays)),
            max(array_map(static fn (StaySeries $s): DateTimeImmutable => $s->newestCapture, $stays)),
        );
    }
}
