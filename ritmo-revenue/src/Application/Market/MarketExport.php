<?php

declare(strict_types=1);

namespace Ritmo\Application\Market;

use DateTimeImmutable;
use Ritmo\Domain\Support\Days;
use Ritmo\Domain\Market\Series\SeriesDefinition;
use Ritmo\Domain\Model\MarketAnchor;
use Ritmo\Infrastructure\Db\Repository\CompetitorRepository;
use Ritmo\Infrastructure\Db\Repository\MarketAnchorRepository;
use Ritmo\Infrastructure\Db\Repository\MarketSeriesRepository;
use Ritmo\Infrastructure\Db\Repository\StaySampleRepository;

/**
 * The rows of the market exports (spec 06, 1.9), as plain values in the documented column order.
 * The CSV writer applies the formula guard to every text cell. Amounts are prices, never
 * differences; factors are given as text so they keep their three decimals. The ruler holds,
 * night by night, the configured series and the market anchor (series "market", percentiles 25,
 * 50, 75, 90 and 100, origin "measured" or "fallback").
 */
final class MarketExport
{
    public const RULER_HEADER = ['night', 'night_type', 'series', 'percentile', 'value', 'currency', 'competitors', 'stays', 'origin', 'distance_days', 'newest_capture', 'oldest_capture'];

    public const SAMPLES_HEADER = ['competitor_code', 'check_in', 'check_out', 'room', 'price', 'price_basis', 'total', 'currency', 'captured_at', 'source', 'available'];

    public const COMPETITORS_HEADER = ['code', 'name', 'reference', 'room_label', 'tags', 'comparable_factor', 'factor_source', 'rating', 'active', 'own_listing'];

    private const MAX_SAMPLES = 50000;

    public function __construct(
        private readonly CompetitorRepository $competitors,
        private readonly StaySampleRepository $samples,
        private readonly MarketSeriesRepository $series,
        private readonly MarketAnchorRepository $anchors,
    ) {
    }

    /**
     * @param callable(DateTimeImmutable): string $typeOf the night type of a night
     *
     * @return iterable<list<string|int|float|null>>
     */
    public function ruler(string $propertyId, string $currency, DateTimeImmutable $from, DateTimeImmutable $to, callable $typeOf): iterable
    {
        $series = $this->series->range($propertyId, $from, $to);
        $anchors = $this->anchors->range($propertyId, $from, $to);
        $nights = array_unique([...array_keys($series), ...array_keys($anchors)]);
        sort($nights);
        foreach ($nights as $night) {
            $type = $typeOf(Days::date($night));
            $anchor = $anchors[$night] ?? null;
            if ($anchor !== null && $anchor->n > 0) {
                yield from self::anchorRows($night, $type, $anchor, $currency);
            }
            foreach ($series[$night] ?? [] as $name => $value) {
                foreach ($value->values as $percentile => $amount) {
                    yield [
                        $night, $type, $name, (int) $percentile, $amount, $currency,
                        $value->competitors, $value->stays, $value->origin, $value->distanceDays,
                        $value->newestCapture === null ? null : Days::key($value->newestCapture),
                        $value->oldestCapture === null ? null : Days::key($value->oldestCapture),
                    ];
                }
            }
        }
    }

    /**
     * @return iterable<list<string|int|float|null>>
     */
    private static function anchorRows(string $night, string $type, MarketAnchor $anchor, string $currency): iterable
    {
        $captured = $anchor->capturedAt === null ? null : Days::key($anchor->capturedAt);
        foreach (['25' => $anchor->p25, '50' => $anchor->p50, '75' => $anchor->p75, '90' => $anchor->p90, '100' => $anchor->max] as $percentile => $amount) {
            yield [$night, $type, SeriesDefinition::ANCHOR, (int) $percentile, $amount, $currency, $anchor->n, null, $anchor->origin->value, null, $captured, $captured];
        }
    }

    /**
     * @return iterable<list<string|int|float|null>>
     */
    public function competitors(string $propertyId): iterable
    {
        foreach ($this->competitors->forProperty($propertyId) as $c) {
            yield [$c['code'], $c['name'], $c['reference'], $c['room_label'], implode(' ', $c['tags']), $c['factor'] === null ? null : rtrim(rtrim(sprintf('%.3F', $c['factor']), '0'), '.'), $c['factor_source'], $c['rating'], $c['active'] ? 1 : 0, $c['is_own'] ? 1 : 0];
        }
    }

    /**
     * @param array{competitor?: int, from?: DateTimeImmutable, to?: DateTimeImmutable, source?: string} $filters
     *
     * @return iterable<list<string|int|float|null>>
     */
    public function samples(string $propertyId, array $filters): iterable
    {
        $codes = array_column($this->competitors->forProperty($propertyId), 'code', 'id');
        foreach ($this->samples->page($propertyId, $filters, 0, self::MAX_SAMPLES)['rows'] as $row) {
            yield [
                $codes[$row['competitor_id']] ?? '',
                Days::key($row['check_in']),
                Days::key(Days::add($row['check_in'], $row['nights'])),
                $row['basis'],
                $row['price'],
                $row['total'] === null ? 'per_night' : 'total',
                $row['total'],
                $row['currency'],
                $row['captured_at']->format('Y-m-d\TH:i:s\Z'),
                $row['source'],
                $row['available'] ? 1 : 0,
            ];
        }
    }
}
