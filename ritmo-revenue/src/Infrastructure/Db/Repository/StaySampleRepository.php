<?php

declare(strict_types=1);

namespace Ritmo\Infrastructure\Db\Repository;

use DateTimeImmutable;
use Ritmo\Domain\Market\Csv\ParsedRow;
use Ritmo\Domain\Market\Series\RoomBasis;
use Ritmo\Domain\Market\Series\StaySample;
use Ritmo\Infrastructure\Db\Database;
use Ritmo\Infrastructure\Db\DbValue;

/**
 * Competitor prices per stay (table price_samples, M4 columns). A row with the deduplication key
 * of a stored row replaces it, so importing the same rows twice changes nothing. Rows of M1 (the
 * "date,price" paste, without a stay) read as one-night comparable stays of their night.
 */
final class StaySampleRepository
{
    private const TABLE = 'price_samples';

    private const STAY_FROM = 'COALESCE(stay_from, night)';

    public function __construct(private readonly Database $db)
    {
    }

    /**
     * Stores the rows of one import: new keys are inserted, known keys replace the stored row.
     *
     * @param list<ParsedRow> $rows
     *
     * @return array{inserted: int, replaced: int}
     */
    public function store(string $propertyId, array $rows, ?int $importId): array
    {
        $inserted = 0;
        $replaced = 0;
        foreach ($rows as $row) {
            $values = [
                'price' => $row->price,
                'total' => $row->total,
                'available' => $row->available,
                'import_id' => $importId,
                'currency' => $row->currency,
            ];
            $existing = $this->db->selectValue(sprintf('SELECT id FROM %s WHERE property_code = ? AND dedup_key = ?', $this->db->table(self::TABLE)), [$propertyId, $row->dedupKey]);
            if ($existing !== null) {
                $this->db->update(self::TABLE, $values, ['id' => (int) $existing]);
                ++$replaced;
                continue;
            }
            $this->db->insert(self::TABLE, $values + [
                'property_code' => $propertyId,
                'competitor_id' => $row->competitorId,
                'night' => DbValue::night($row->checkIn),
                'stay_from' => DbValue::night($row->checkIn),
                'stay_nights' => $row->nights,
                'room_basis' => $row->basis->value,
                'plan' => 'public',
                'source' => DbValue::word($row->source, 32),
                'captured_at' => DbValue::instant($row->capturedAt),
                'dedup_key' => $row->dedupKey,
            ]);
            ++$inserted;
        }

        return ['inserted' => $inserted, 'replaced' => $replaced];
    }

    /**
     * The samples of stays starting on or after a night, captured at or after an instant, for
     * the series. The sequence is the row id (a later import, then a later line, is higher).
     *
     * @return list<StaySample>
     */
    public function samples(string $propertyId, DateTimeImmutable $fromNight, DateTimeImmutable $capturedSince): array
    {
        $rows = $this->db->select(
            sprintf('SELECT id, competitor_id, %1$s AS stay, stay_nights, room_basis, price, total, captured_at, available FROM %2$s WHERE property_code = ? AND %1$s >= ? AND captured_at >= ? AND price > 0 ORDER BY id', self::STAY_FROM, $this->db->table(self::TABLE)),
            [$propertyId, DbValue::night($fromNight), DbValue::instant($capturedSince)]
        );

        return array_map(static fn (array $row): StaySample => new StaySample(
            (int) $row['competitor_id'],
            DbValue::requireNight($row['stay']),
            max(1, min(StaySample::MAX_NIGHTS, (int) $row['stay_nights'])),
            RoomBasis::tryFrom((string) $row['room_basis']) ?? RoomBasis::Comparable,
            (float) $row['price'],
            DbValue::nullableFloat($row['total']) > 0 ? DbValue::nullableFloat($row['total']) : null,
            DbValue::requireInstant($row['captured_at']),
            (bool) (int) $row['available'],
            (int) $row['id'],
        ), $rows);
    }

    /**
     * One page of the samples of a property, newest stay first, with optional filters.
     *
     * @param array{competitor?: int, from?: DateTimeImmutable, to?: DateTimeImmutable, source?: string} $filters
     *
     * @return array{rows: list<array{id: int, competitor_id: int, check_in: DateTimeImmutable, nights: int, basis: string, price: float, total: float|null, currency: string, captured_at: DateTimeImmutable, source: string, available: bool}>, total: int}
     */
    public function page(string $propertyId, array $filters, int $offset, int $limit): array
    {
        [$where, $params] = self::where($propertyId, $filters);
        $table = $this->db->table(self::TABLE);
        $total = (int) $this->db->selectValue(sprintf('SELECT COUNT(*) FROM %s WHERE %s', $table, $where), $params);
        $rows = $this->db->select(
            sprintf('SELECT id, competitor_id, %s AS stay, stay_nights, room_basis, price, total, currency, captured_at, source, available FROM %s WHERE %s ORDER BY stay DESC, id DESC LIMIT ? OFFSET ?', self::STAY_FROM, $table, $where),
            [...$params, max(1, min(50000, $limit)), max(0, $offset)]
        );

        return ['rows' => array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'competitor_id' => (int) $row['competitor_id'],
            'check_in' => DbValue::requireNight($row['stay']),
            'nights' => (int) $row['stay_nights'],
            'basis' => (string) $row['room_basis'],
            'price' => (float) $row['price'],
            'total' => DbValue::nullableFloat($row['total']),
            'currency' => (string) $row['currency'],
            'captured_at' => DbValue::requireInstant($row['captured_at']),
            'source' => (string) $row['source'],
            'available' => (bool) (int) $row['available'],
        ], $rows), 'total' => $total];
    }

    /**
     * The newest capture of a sample whose stay starts within a range of nights; null when none.
     */
    public function newestCapture(string $propertyId, DateTimeImmutable $from, DateTimeImmutable $to): ?DateTimeImmutable
    {
        return DbValue::toInstant($this->db->selectValue(
            sprintf('SELECT MAX(captured_at) FROM %s WHERE property_code = ? AND available = 1 AND %2$s >= ? AND %2$s <= ?', $this->db->table(self::TABLE), self::STAY_FROM),
            [$propertyId, DbValue::night($from), DbValue::night($to)]
        ));
    }

    /**
     * The number of samples captured since an instant, per competitor.
     *
     * @return array<int, int>
     */
    public function countsByCompetitor(string $propertyId, DateTimeImmutable $since): array
    {
        $counts = [];
        foreach ($this->db->select(
            sprintf('SELECT competitor_id, COUNT(*) AS n FROM %s WHERE property_code = ? AND captured_at >= ? GROUP BY competitor_id', $this->db->table(self::TABLE)),
            [$propertyId, DbValue::instant($since)]
        ) as $row) {
            $counts[(int) $row['competitor_id']] = (int) $row['n'];
        }

        return $counts;
    }

    /**
     * The highest sample id of a property (an input of the series cache); 0 when none.
     */
    public function lastId(string $propertyId): int
    {
        return (int) $this->db->selectValue(sprintf('SELECT MAX(id) FROM %s WHERE property_code = ?', $this->db->table(self::TABLE)), [$propertyId]);
    }

    /**
     * @param array{competitor?: int, from?: DateTimeImmutable, to?: DateTimeImmutable, source?: string} $filters
     *
     * @return array{0: string, 1: list<int|string>}
     */
    private static function where(string $propertyId, array $filters): array
    {
        $where = ['property_code = ?'];
        $params = [$propertyId];
        if (isset($filters['competitor'])) {
            $where[] = 'competitor_id = ?';
            $params[] = $filters['competitor'];
        }
        if (isset($filters['from'])) {
            $where[] = self::STAY_FROM . ' >= ?';
            $params[] = DbValue::night($filters['from']);
        }
        if (isset($filters['to'])) {
            $where[] = self::STAY_FROM . ' <= ?';
            $params[] = DbValue::night($filters['to']);
        }
        if (isset($filters['source'])) {
            $where[] = 'source = ?';
            $params[] = $filters['source'];
        }

        return [implode(' AND ', $where), $params];
    }
}
