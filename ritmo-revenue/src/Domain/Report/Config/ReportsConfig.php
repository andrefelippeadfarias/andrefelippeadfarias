<?php

declare(strict_types=1);

namespace Ritmo\Domain\Report\Config;

use DateTimeImmutable;
use Ritmo\Domain\Config\InvalidConfig;
use Ritmo\Domain\Pricing\Ladder\ConfigReader;
use Ritmo\Domain\Report\Occupancy\OccupancyGroup;
use Ritmo\Domain\Report\Review\ReviewConfig;
use Ritmo\Domain\Report\Targets\ForecastConfig;
use Ritmo\Domain\Report\Targets\Scenario;

/**
 * The "reports" section read and checked.
 */
final class ReportsConfig
{
    /**
     * @param list<OccupancyGroup> $groups
     */
    public function __construct(
        public readonly int $mapNights,
        public readonly array $groups,
        public readonly ReviewConfig $review,
        public readonly ?DateTimeImmutable $weeklyStart,
        public readonly ForecastConfig $forecast,
    ) {
    }

    public static function neutral(): self
    {
        return self::fromArray([]);
    }

    /**
     * @param array<string, mixed> $data the "reports" section; missing keys take the defaults
     *
     * @throws InvalidConfig with every problem found
     */
    public static function fromArray(array $data): self
    {
        $reader = new ConfigReader(ReportsFields::all());
        $r = ReportsFields::ROOT;
        $d = $reader->open($r, $data);
        $groups = [];
        foreach ($reader->items($d['occupancy']['groups'], "$r.occupancy.groups") as $i => $group) {
            $tags = [];
            foreach ($reader->items($group['tags'] ?? null, "$r.occupancy.groups.$i.tags") as $j => $tag) {
                $tags[] = $reader->string($tag, "$r.occupancy.groups.$i.tags.$j");
            }
            $label = $reader->string($group['label'] ?? null, "$r.occupancy.groups.$i.label");
            if ($label !== '' && $tags !== []) {
                $groups[] = new OccupancyGroup($label, $tags);
            }
        }
        $review = $d['review'];
        $priceTags = [];
        foreach ($reader->items($review['price_tags'], "$r.review.price_tags") as $i => $tag) {
            $priceTags[] = $reader->string($tag, "$r.review.price_tags.$i");
        }
        $mapNights = $reader->int($d['occupancy']['map_nights'], "$r.occupancy.map_nights");
        $interval = $reader->int($review['interval_days'], "$r.review.interval_days");
        $ahead = $reader->float($review['ahead_ratio'], "$r.review.ahead_ratio");
        $behind = $reader->float($review['behind_ratio'], "$r.review.behind_ratio");
        $early = $reader->float($review['early_market_percent'], "$r.review.early_market_percent");
        $start = $reader->date($d['weekly']['start_date'], "$r.weekly.start_date");
        $forecast = self::forecast($reader, $d['forecast']);
        $reader->finish('reports');

        return new self($mapNights, $groups, new ReviewConfig($interval, $ahead, $behind, $early, $priceTags), $start, new ForecastConfig(...$forecast));
    }

    /**
     * The arguments of the forecast settings (built once every value was checked).
     *
     * @param array<string, mixed> $f
     *
     * @return array<string, mixed>
     */
    private static function forecast(ConfigReader $reader, array $f): array
    {
        $p = ReportsFields::ROOT . '.forecast';
        $growth = $index = [];
        foreach (Scenario::cases() as $scenario) {
            $s = $f['scenarios'][$scenario->value];
            $growth[$scenario->value] = $reader->float($s['growth'], "$p.scenarios.{$scenario->value}.growth");
            $index[$scenario->value] = self::numbers($reader, $s['index'], "$p.scenarios.{$scenario->value}.index");
        }
        $high = [];
        foreach ($reader->items($f['high_months'], "$p.high_months") as $i => $month) {
            $high[] = $reader->int($month, "$p.high_months.$i");
        }

        return [
            'growth' => $growth,
            'index' => $index,
            'realisticGrowth' => $reader->float($f['realistic_growth'], "$p.realistic_growth"),
            'maximumIndex' => self::numbers($reader, $f['maximum_index'], "$p.maximum_index"),
            'highMonths' => $high,
            'seasonMinimum' => [
                'high' => self::numbers($reader, $f['season_minimum']['high'], "$p.season_minimum.high"),
                'low' => self::numbers($reader, $f['season_minimum']['low'], "$p.season_minimum.low"),
            ],
        ];
    }

    /**
     * @return array<string, float>
     */
    private static function numbers(ConfigReader $reader, mixed $map, string $path): array
    {
        $out = [];
        foreach ($reader->items($map, $path) as $key => $value) {
            $out[(string) $key] = $reader->float($value, "$path.$key");
        }

        return $out;
    }
}
