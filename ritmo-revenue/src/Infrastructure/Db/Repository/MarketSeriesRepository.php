<?php

declare(strict_types=1);

namespace Ritmo\Infrastructure\Db\Repository;

use DateTimeImmutable;
use Ritmo\Domain\Market\Series\NightValue;
use Ritmo\Domain\Support\Days;
use Ritmo\Infrastructure\Db\Database;
use Ritmo\Infrastructure\Db\DbValue;

/**
 * The value of each market series on each night of a property: a cache rebuilt as a whole from
 * the samples (after an import, a competitor change, a change of the market section, or when its
 * inputs hash differs at the start of a full run). Each build also writes one marker row (empty
 * series name) that holds the inputs hash, so a build with no value is remembered too.
 */
final class MarketSeriesRepository
{
    private const TABLE = 'market_series';

    private const MARKER = '';

    public function __construct(private readonly Database $db)
    {
    }

    /**
     * Replaces every stored value of the property.
     *
     * @param array<string, array<string, NightValue>> $values night ("YYYY-MM-DD") => series => value
     */
    public function replace(string $propertyId, array $values, string $inputsHash, DateTimeImmutable $builtAt): int
    {
        return $this->db->transaction(function () use ($propertyId, $values, $inputsHash, $builtAt): int {
            $this->db->delete(self::TABLE, ['property_code' => $propertyId]);
            $this->db->insert(self::TABLE, [
                'property_code' => $propertyId,
                'night' => DbValue::night($builtAt),
                'series' => self::MARKER,
                'series_values' => DbValue::json([]),
                'origin' => 'build',
                'inputs_hash' => $inputsHash,
                'built_at' => DbValue::instant($builtAt),
            ]);
            $count = 0;
            foreach ($values as $night => $bySeries) {
                foreach ($bySeries as $series => $value) {
                    $this->db->insert(self::TABLE, [
                        'property_code' => $propertyId,
                        'night' => DbValue::night(Days::date((string) $night)),
                        'series' => DbValue::word((string) $series, 32),
                        'series_values' => DbValue::json($value->values),
                        'competitors' => $value->competitors,
                        'stays' => $value->stays,
                        'origin' => $value->origin,
                        'distance_days' => $value->distanceDays,
                        'oldest_capture' => DbValue::nullableNight($value->oldestCapture),
                        'newest_capture' => DbValue::nullableNight($value->newestCapture),
                        'inputs_hash' => $inputsHash,
                        'built_at' => DbValue::instant($builtAt),
                    ]);
                    ++$count;
                }
            }

            return $count;
        });
    }

    /**
     * The stored values of a range of nights.
     *
     * @return array<string, array<string, NightValue>> night => series => value
     */
    public function range(string $propertyId, DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $values = [];
        foreach ($this->db->select(
            sprintf('SELECT * FROM %s WHERE property_code = ? AND series <> ? AND night >= ? AND night <= ? ORDER BY night, series', $this->db->table(self::TABLE)),
            [$propertyId, self::MARKER, DbValue::night($from), DbValue::night($to)]
        ) as $row) {
            $points = [];
            foreach (DbValue::toArray($row['series_values']) as $p => $v) {
                $points[(string) $p] = (int) $v;
            }
            $values[Days::key(DbValue::requireNight($row['night']))][(string) $row['series']] = new NightValue(
                $points,
                (string) $row['origin'],
                DbValue::nullableInt($row['distance_days']),
                (int) $row['stays'],
                (int) $row['competitors'],
                DbValue::toNight($row['oldest_capture']),
                DbValue::toNight($row['newest_capture']),
            );
        }

        return $values;
    }

    /**
     * The inputs hash of the stored values; null when nothing was built.
     */
    public function inputsHash(string $propertyId): ?string
    {
        $hash = $this->db->selectValue(sprintf('SELECT inputs_hash FROM %s WHERE property_code = ? AND series = ?', $this->db->table(self::TABLE)), [$propertyId, self::MARKER]);

        return $hash === null ? null : (string) $hash;
    }
}
