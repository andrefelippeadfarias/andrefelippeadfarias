<?php

declare(strict_types=1);

namespace Ritmo\Domain\Market\Series;

use Ritmo\Domain\Config\InvalidConfig;
use Ritmo\Domain\Config\ValidationError;
use Ritmo\Domain\Pricing\Ladder\ConfigReader;

/**
 * The "market" section read and checked: the series, the percentiles, the thresholds of a stay
 * and of a night, the validity of a price, the CSV limits and the market bands.
 */
final class MarketConfig
{
    /**
     * @param list<SeriesDefinition>                         $series
     * @param list<int>                                      $percentiles ascending
     * @param list<array{label: string, percentile: int}>    $bands
     */
    public function __construct(
        public readonly array $series,
        public readonly array $percentiles,
        public readonly int $minCompetitorsPerStay = 10,
        public readonly int $nightMinCompetitors = 20,
        public readonly int $neighbourDays = 3,
        public readonly int $maxSampleAgeDays = 30,
        public readonly int $retentionDays = 400,
        public readonly float $defaultFactor = 1.0,
        public readonly float $estimatedFactorCap = 2.0,
        public readonly int $smallSampleStays = 4,
        public readonly int $smallSampleCompetitors = 40,
        public readonly int $staleAfterDays = 7,
        public readonly int $reminderHorizonDays = 60,
        public readonly int $csvMaxBytes = 2097152,
        public readonly int $csvMaxRows = 20000,
        public readonly int $csvMaxStayNights = 7,
        public readonly array $bands = [],
    ) {
    }

    public static function neutral(): self
    {
        return self::fromArray([]);
    }

    /**
     * @param array<string, mixed> $data the "market" section; missing keys take the defaults
     *
     * @throws InvalidConfig with every problem found
     */
    public static function fromArray(array $data): self
    {
        $reader = new ConfigReader(MarketFields::all());
        $m = MarketFields::ROOT;
        $d = $reader->open($m, $data);

        $series = self::series($reader, $reader->items($d['series'], "$m.series"));
        $percentiles = [];
        foreach ($reader->items($d['percentiles'], "$m.percentiles") as $i => $p) {
            $percentiles[] = $reader->int($p, "$m.percentiles.$i");
        }
        $sorted = $percentiles;
        sort($sorted);
        if ($sorted !== $percentiles) {
            $reader->add("$m.percentiles", ValidationError::ORDER, 'must go from the lowest to the highest');
        }
        $bands = [];
        foreach ($reader->items($d['bands'], "$m.bands") as $i => $band) {
            $percentile = $reader->int($band['percentile'] ?? null, "$m.bands.$i.percentile");
            $bands[] = ['label' => $reader->string($band['label'] ?? null, "$m.bands.$i.label"), 'percentile' => $percentile];
            if (!in_array($percentile, $percentiles, true)) {
                $reader->add("$m.bands.$i.percentile", ValidationError::REFERENCE, 'is not one of the percentiles kept');
            }
        }

        $config = new self(
            $series,
            $percentiles,
            $reader->int($d['min_competitors_per_stay'], "$m.min_competitors_per_stay"),
            $reader->int($d['night_min_competitors'], "$m.night_min_competitors"),
            $reader->int($d['neighbour_days'], "$m.neighbour_days"),
            $reader->int($d['max_sample_age_days'], "$m.max_sample_age_days"),
            $reader->int($d['retention_days'], "$m.retention_days"),
            $reader->float($d['comparable']['default_factor'], "$m.comparable.default_factor"),
            $reader->float($d['comparable']['estimated_factor_cap'], "$m.comparable.estimated_factor_cap"),
            $reader->int($d['small_sample']['stays'], "$m.small_sample.stays"),
            $reader->int($d['small_sample']['competitors'], "$m.small_sample.competitors"),
            $reader->int($d['stale_after_days'], "$m.stale_after_days"),
            $reader->int($d['reminder_horizon_days'], "$m.reminder_horizon_days"),
            $reader->int($d['csv']['max_bytes'], "$m.csv.max_bytes"),
            $reader->int($d['csv']['max_rows'], "$m.csv.max_rows"),
            $reader->int($d['csv']['max_stay_nights'], "$m.csv.max_stay_nights"),
            $bands,
        );
        $reader->finish('market');

        return $config;
    }

    /**
     * The configured series by name.
     *
     * @return array<string, SeriesDefinition>
     */
    public function seriesByName(): array
    {
        $byName = [];
        foreach ($this->series as $series) {
            $byName[$series->name] = $series;
        }

        return $byName;
    }

    /**
     * @param array<int|string, mixed> $rows
     *
     * @return list<SeriesDefinition>
     */
    private static function series(ConfigReader $reader, array $rows): array
    {
        $m = MarketFields::ROOT;
        $series = [];
        $names = [];
        foreach ($rows as $i => $row) {
            $row = is_array($row) ? $row : [];
            $name = $reader->string($row['name'] ?? null, "$m.series.$i.name");
            $basis = RoomBasis::tryFrom((string) $reader->choice($row['basis'] ?? null, "$m.series.$i.basis")) ?? RoomBasis::Cheapest;
            $tags = [];
            foreach ($reader->items($row['tags'] ?? [], "$m.series.$i.tags") as $t => $tag) {
                $tags[] = $reader->string($tag, "$m.series.$i.tags.$t");
            }
            if ($name === SeriesDefinition::ANCHOR) {
                $reader->add("$m.series.$i.name", ValidationError::FORMAT, 'is the name of the market anchor series; choose another name');
                continue;
            }
            if (isset($names[$name])) {
                $reader->add("$m.series.$i.name", ValidationError::UNIQUE, 'is already the name of another series');
                continue;
            }
            $names[$name] = true;
            if (preg_match(SeriesDefinition::NAME_PATTERN, $name) === 1) {
                $series[] = new SeriesDefinition($name, $basis, array_values(array_filter($tags, static fn (string $t): bool => preg_match(CompetitorProfile::TAG_PATTERN, $t) === 1)));
            }
        }

        return $series;
    }
}
