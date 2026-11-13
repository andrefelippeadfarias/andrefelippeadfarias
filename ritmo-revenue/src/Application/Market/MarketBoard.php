<?php

declare(strict_types=1);

namespace Ritmo\Application\Market;

use DateTimeImmutable;
use Ritmo\Domain\Config\RuleSet;
use Ritmo\Domain\Market\Series\MarketConfig;
use Ritmo\Domain\Market\Series\NightValue;
use Ritmo\Domain\Model\MarketAnchor;
use Ritmo\Domain\Model\Property;
use Ritmo\Domain\Support\Clock;
use Ritmo\Domain\Support\Days;
use Ritmo\Infrastructure\Db\Repository\CompetitorRepository;
use Ritmo\Infrastructure\Db\Repository\MarketAnchorRepository;
use Ritmo\Infrastructure\Db\Repository\MarketImportRepository;
use Ritmo\Infrastructure\Db\Repository\MarketSeriesRepository;
use Ritmo\Infrastructure\Db\Repository\StaySampleRepository;

/**
 * What the market screen shows, as plain values (no HTML, no text): the series per night with
 * where each value came from and its age, the competitors with their recent prices, a page of
 * prices and the latest imports. Read only.
 */
final class MarketBoard
{
    public const PAGE_NIGHTS = 30;

    public const PAGE_PRICES = 100;

    public function __construct(
        private readonly Clock $clock,
        private readonly CompetitorRepository $competitors,
        private readonly StaySampleRepository $samples,
        private readonly MarketSeriesRepository $series,
        private readonly MarketAnchorRepository $anchors,
        private readonly MarketImportRepository $imports,
    ) {
    }

    /**
     * One page of nights from $from: per night its type, the configured series and the anchor.
     *
     * @return list<array{night: DateTimeImmutable, type: string, series: array<string, NightValue>, anchor: MarketAnchor|null, age_days: int|null, small_sample: bool}>
     */
    public function nights(Property $property, RuleSet $rules, MarketConfig $config, DateTimeImmutable $from, int $count = self::PAGE_NIGHTS): array
    {
        $to = Days::add($from, max(1, $count) - 1);
        $values = $this->series->range($property->id, $from, $to);
        $anchors = $this->anchors->range($property->id, $from, $to);
        $today = $this->clock->today($property->timezone);
        $rows = [];
        for ($night = Days::normalize($from); $night <= $to; $night = Days::add($night, 1)) {
            $key = Days::key($night);
            $series = $values[$key] ?? [];
            $newest = null;
            $small = false;
            foreach ($series as $value) {
                $newest = $value->newestCapture === null ? $newest : max($newest ?? $value->newestCapture, $value->newestCapture);
                $small = $small || $value->stays < $config->smallSampleStays || $value->competitors < $config->smallSampleCompetitors;
            }
            $rows[] = [
                'night' => $night,
                'type' => $rules->nightType($night)->value,
                'series' => $series,
                'anchor' => $anchors[$key] ?? null,
                'age_days' => $newest === null ? null : max(0, Days::diff($newest, $today)),
                'small_sample' => $small,
            ];
        }

        return $rows;
    }

    /**
     * The competitors with how many prices they have from the last 30 days.
     *
     * @return list<array{id: int, code: string, name: string, reference: string, room_label: string, tags: list<string>, factor: float|null, factor_source: string, rating: float|null, is_own: bool, active: bool, prices_30_days: int}>
     */
    public function competitors(string $propertyId): array
    {
        $counts = $this->samples->countsByCompetitor($propertyId, $this->clock->now()->modify('-30 days'));

        return array_map(static function (array $row) use ($counts): array {
            unset($row['updated_at']);

            return $row + ['prices_30_days' => $counts[$row['id']] ?? 0];
        }, $this->competitors->forProperty($propertyId));
    }

    /**
     * @param array{competitor?: int, from?: DateTimeImmutable, to?: DateTimeImmutable, source?: string} $filters
     *
     * @return array{rows: list<array<string, mixed>>, total: int}
     */
    public function prices(string $propertyId, array $filters, int $page): array
    {
        return $this->samples->page($propertyId, $filters, max(0, $page - 1) * self::PAGE_PRICES, self::PAGE_PRICES);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function imports(string $propertyId): array
    {
        return $this->imports->latest($propertyId, 10);
    }
}
