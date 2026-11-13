<?php

declare(strict_types=1);

namespace Ritmo\Infrastructure\Db\Repository;

use DateTimeImmutable;
use Ritmo\Domain\Support\Days;
use Ritmo\Infrastructure\Db\Database;
use Ritmo\Infrastructure\Db\DbValue;

/**
 * The reference sent prices of a sales ladder strategy (its opening cap "reference_snapshot"):
 * taken once per version, never edited. Taking them again writes a new version.
 */
final class LadderReferenceRepository
{
    private const TABLE = 'ladder_reference';

    public function __construct(private readonly Database $db)
    {
    }

    /**
     * Writes a new version of the strategy's reference prices and returns its number.
     *
     * @param array<string, array<string, float>> $prices room code => night ("YYYY-MM-DD") => sent price
     */
    public function take(string $propertyId, string $strategyId, array $prices, DateTimeImmutable $takenAt): int
    {
        return $this->db->transaction(function () use ($propertyId, $strategyId, $prices, $takenAt): int {
            $version = ($this->latestVersion($propertyId, $strategyId) ?? 0) + 1;
            foreach ($prices as $room => $nights) {
                foreach ($nights as $night => $price) {
                    $this->db->insert(self::TABLE, [
                        'property_code' => $propertyId,
                        'strategy_id' => DbValue::word($strategyId, 64),
                        'room_code' => DbValue::word((string) $room, 32),
                        'night' => DbValue::night(Days::date((string) $night)),
                        'sent_price' => $price,
                        'version' => $version,
                        'taken_at' => DbValue::instant($takenAt),
                    ]);
                }
            }

            return $version;
        });
    }

    public function latestVersion(string $propertyId, string $strategyId): ?int
    {
        return DbValue::nullableInt($this->db->selectValue(
            sprintf('SELECT MAX(version) FROM %s WHERE property_code = ? AND strategy_id = ?', $this->db->table(self::TABLE)),
            [$propertyId, $strategyId]
        ));
    }

    /**
     * The reference prices of the latest version for a range of nights.
     *
     * @return array<string, array<string, float>> night => room code => sent price
     */
    public function latest(string $propertyId, string $strategyId, DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $version = $this->latestVersion($propertyId, $strategyId);
        if ($version === null) {
            return [];
        }
        $prices = [];
        foreach ($this->db->select(
            sprintf('SELECT room_code, night, sent_price FROM %s WHERE property_code = ? AND strategy_id = ? AND version = ? AND night >= ? AND night <= ? ORDER BY night, room_code', $this->db->table(self::TABLE)),
            [$propertyId, $strategyId, $version, DbValue::night($from), DbValue::night($to)]
        ) as $row) {
            $prices[Days::key(DbValue::requireNight($row['night']))][(string) $row['room_code']] = (float) $row['sent_price'];
        }

        return $prices;
    }
}
