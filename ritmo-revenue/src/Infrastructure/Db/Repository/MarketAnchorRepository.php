<?php

declare(strict_types=1);

namespace Ritmo\Infrastructure\Db\Repository;

use DateTimeImmutable;
use Ritmo\Domain\Model\AnchorOrigin;
use Ritmo\Domain\Model\MarketAnchor;
use Ritmo\Domain\Support\Clock;
use Ritmo\Infrastructure\Db\Database;
use Ritmo\Infrastructure\Db\DbValue;

/**
 * The market anchor of each property and night: one row per night, replaced when a newer
 * anchor is computed.
 */
final class MarketAnchorRepository
{
    private const TABLE = 'market_anchor';

    public function __construct(private readonly Database $db, private readonly Clock $clock)
    {
    }

    public function save(string $propertyId, MarketAnchor $anchor): void
    {
        $this->db->upsert(self::TABLE, [
            'property_code' => $propertyId,
            'night' => DbValue::night($anchor->date),
            'p25' => $anchor->p25,
            'p50' => $anchor->p50,
            'p75' => $anchor->p75,
            'p90' => $anchor->p90,
            'max_price' => $anchor->max,
            'sample_size' => $anchor->n,
            'captured_on' => DbValue::nullableNight($anchor->capturedAt),
            'origin' => $anchor->origin->value,
            'updated_at' => DbValue::instant($this->clock->now()),
        ], ['property_code', 'night']);
    }

    /**
     * Stores every anchor, all or none.
     *
     * @param iterable<MarketAnchor> $anchors
     */
    public function saveMany(string $propertyId, iterable $anchors): void
    {
        $this->db->transaction(function () use ($propertyId, $anchors): void {
            foreach ($anchors as $anchor) {
                $this->save($propertyId, $anchor);
            }
        });
    }

    public function find(string $propertyId, DateTimeImmutable $night): ?MarketAnchor
    {
        return $this->range($propertyId, $night, $night)[DbValue::night($night)] ?? null;
    }

    /**
     * The anchors of a property in a range of nights, in the shape of
     * MarketDataProvider::readAnchors().
     *
     * @param DateTimeImmutable $from first night, inclusive
     * @param DateTimeImmutable $to   last night, inclusive
     *
     * @return array<string, MarketAnchor> keyed by "YYYY-MM-DD", ascending; nights without an anchor are absent
     */
    public function range(string $propertyId, DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $rows = $this->db->select(
            sprintf('SELECT * FROM %s WHERE property_code = ? AND night >= ? AND night <= ? ORDER BY night', $this->db->table(self::TABLE)),
            [$propertyId, DbValue::night($from), DbValue::night($to)]
        );

        $anchors = [];
        foreach ($rows as $row) {
            $night = DbValue::requireNight($row['night']);
            $anchors[DbValue::night($night)] = new MarketAnchor(
                $night,
                (float) $row['p25'],
                (float) $row['p50'],
                (float) $row['p75'],
                (float) $row['p90'],
                (float) $row['max_price'],
                (int) $row['sample_size'],
                DbValue::toNight($row['captured_on']),
                AnchorOrigin::from((string) $row['origin'])
            );
        }

        return $anchors;
    }
}
