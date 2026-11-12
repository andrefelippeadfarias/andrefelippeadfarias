<?php

declare(strict_types=1);

namespace Ritmo\Infrastructure\Db\Repository;

use DateTimeImmutable;
use InvalidArgumentException;
use Ritmo\Domain\Model\PricePlan;
use Ritmo\Infrastructure\Db\Database;
use Ritmo\Infrastructure\Db\DbValue;

/**
 * Competitor prices collected for a property: typed by hand, imported from a file or read
 * from the pricing system's market data. Samples are only added; the anchor is computed
 * from the recent ones.
 */
final class PriceSampleRepository
{
    private const TABLE = 'price_samples';

    public function __construct(private readonly Database $db)
    {
    }

    /**
     * @param int               $competitorId id of the competitor row; 0 when the sample has none
     * @param float             $price        nightly price in the property currency, above zero
     * @param DateTimeImmutable $capturedAt   instant the price was seen
     * @param string            $source       where it came from ("manual", "csv", a connector id)
     * @param bool              $available    false when the competitor was sold out at that price check
     *
     * @return int id of the sample
     *
     * @throws InvalidArgumentException on a price that is not a positive finite number
     */
    public function add(
        string $propertyId,
        int $competitorId,
        DateTimeImmutable $night,
        float $price,
        DateTimeImmutable $capturedAt,
        string $source = 'manual',
        PricePlan $plan = PricePlan::PublicRate,
        bool $available = true,
        ?int $minStay = null,
    ): int {
        if (!is_finite($price) || $price <= 0) {
            throw new InvalidArgumentException('A price sample needs a price above zero.');
        }

        return $this->db->insert(self::TABLE, [
            'property_code' => $propertyId,
            'competitor_id' => max(0, $competitorId),
            'night' => DbValue::night($night),
            'price' => $price,
            'plan' => $plan->value,
            'available' => $available,
            'min_stay' => $minStay,
            'source' => DbValue::word($source, 32),
            'captured_at' => DbValue::instant($capturedAt),
        ]);
    }

    /**
     * The samples of a property for a range of nights, by night and then by capture.
     *
     * @param DateTimeImmutable      $from          first night, inclusive
     * @param DateTimeImmutable      $to            last night, inclusive
     * @param DateTimeImmutable|null $capturedSince only samples seen at or after this instant
     *
     * @return list<array{id: int, property_id: string, competitor_id: int, night: DateTimeImmutable, price: float, plan: PricePlan, available: bool, min_stay: int|null, source: string, captured_at: DateTimeImmutable}>
     */
    public function forNights(string $propertyId, DateTimeImmutable $from, DateTimeImmutable $to, ?DateTimeImmutable $capturedSince = null): array
    {
        $sql = sprintf('SELECT * FROM %s WHERE property_code = ? AND night >= ? AND night <= ?', $this->db->table(self::TABLE));
        $params = [$propertyId, DbValue::night($from), DbValue::night($to)];
        if ($capturedSince !== null) {
            $sql .= ' AND captured_at >= ?';
            $params[] = DbValue::instant($capturedSince);
        }

        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'property_id' => (string) $row['property_code'],
            'competitor_id' => (int) $row['competitor_id'],
            'night' => DbValue::requireNight($row['night']),
            'price' => (float) $row['price'],
            'plan' => PricePlan::from((string) $row['plan']),
            'available' => (bool) (int) $row['available'],
            'min_stay' => DbValue::nullableInt($row['min_stay']),
            'source' => (string) $row['source'],
            'captured_at' => DbValue::requireInstant($row['captured_at']),
        ], $this->db->select($sql . ' ORDER BY night, captured_at, id', $params));
    }

    /**
     * Removes the samples seen before an instant (data retention).
     *
     * @return int rows removed
     */
    public function purgeCapturedBefore(DateTimeImmutable $instant): int
    {
        return $this->db->execute(
            sprintf('DELETE FROM %s WHERE captured_at < ?', $this->db->table(self::TABLE)),
            [DbValue::instant($instant)]
        );
    }
}
