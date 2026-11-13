<?php

declare(strict_types=1);

namespace Ritmo\Domain\Market\Series;

use Closure;
use DateTimeImmutable;
use Ritmo\Domain\Support\Num;

/**
 * Measures every configured series on every stay: the stays that have enough competitor values
 * for a series get its percentiles.
 *
 * Reference: faixas_dono.construir. A stay counts for a series with at least
 * market.min_competitors_per_stay values; its percentiles are Python's round of the percentile of
 * the values; stays are listed by check-in (ties keep the order they first appeared in). A stay
 * whose type is unknown (its nights have different types) is left out of every series.
 */
final class SeriesBuilder
{
    /**
     * @param array<int, CompetitorProfile> $catalogue by competitor id
     * @param Closure(DateTimeImmutable, int): ?string $typeOf night type label of a stay (check-in, nights); null leaves the stay out
     */
    public function __construct(
        private readonly MarketConfig $config,
        private readonly array $catalogue,
        private readonly Closure $typeOf,
    ) {
    }

    /**
     * @param list<StaySample> $samples every sample of the property, any stay and basis
     *
     * @return array<string, list<StaySeries>> series name => its stays, by check-in
     */
    public function build(array $samples, SampleWindow $window): array
    {
        $stays = [];
        foreach (NewestSamples::of(array_values(array_filter($samples, $window->admits(...)))) as $sample) {
            $stays[$sample->stayKey()][] = $sample;
        }
        uasort($stays, static fn (array $a, array $b): int => $a[0]->checkIn <=> $b[0]->checkIn);

        $built = [];
        foreach ($this->config->series as $series) {
            $built[$series->name] = [];
        }
        foreach ($stays as $stay) {
            $type = ($this->typeOf)($stay[0]->checkIn, $stay[0]->nights);
            if ($type === null) {
                continue;
            }
            foreach ($this->config->series as $series) {
                $measured = $this->measure($stay, $series, $type);
                if ($measured !== null) {
                    $built[$series->name][] = $measured;
                }
            }
        }

        return $built;
    }

    /**
     * @param list<StaySample> $stay
     */
    private function measure(array $stay, SeriesDefinition $series, string $type): ?StaySeries
    {
        $values = StayValues::of($stay, $this->catalogue, $series, $this->config->defaultFactor, $this->config->estimatedFactorCap);
        if ($values === [] || count($values) < $this->config->minCompetitorsPerStay) {
            return null;
        }
        $percentiles = [];
        foreach ($this->config->percentiles as $p) {
            $percentiles[(string) $p] = Num::roundInt(Percentile::of($values, $p));
        }
        $rounded = array_map(static fn (float $v): float => Num::roundHalfEven($v, 2), $values);
        sort($rounded);
        $captures = [];
        foreach ($stay as $sample) {
            $competitor = $this->catalogue[$sample->competitorId] ?? null;
            if ($sample->available && $competitor !== null && $series->takes($competitor)) {
                $captures[] = $sample->capturedAt;
            }
        }

        return new StaySeries($series->name, $stay[0]->checkIn, $stay[0]->nights, $type, count($values), $percentiles, $rounded, min($captures), max($captures));
    }
}
