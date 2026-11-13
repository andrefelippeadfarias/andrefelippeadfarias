<?php

declare(strict_types=1);

namespace Ritmo\Infrastructure\Db\Repository;

use DateTimeImmutable;
use Ritmo\Domain\Write\State\NightFields;
use Ritmo\Domain\Write\State\Provider;
use Ritmo\Domain\Write\ThirdParty\Observation;
use Ritmo\Infrastructure\Db\Database;
use Ritmo\Infrastructure\Db\DbValue;

/**
 * What each read found per room night (table night_observations): the runs' reads
 * and the read-backs after the plugin's writes. A night is stored again only when its values
 * changed, so the table grows with changes, not with runs. Old superseded rows are purged.
 */
final class NightObservationRepository
{
    private const TABLE = 'night_observations';

    public function __construct(private readonly Database $db)
    {
    }

    /**
     * Stores the observations whose values differ from the latest of their night.
     *
     * @param list<Observation> $observations
     *
     * @return int rows stored
     */
    public function recordChanged(string $propertyId, array $observations, ?int $runId = null): int
    {
        if ($observations === []) {
            return 0;
        }
        $nights = array_map(static fn (Observation $o): DateTimeImmutable => $o->night, $observations);
        $latest = $this->latest($propertyId, min($nights), max($nights));

        return $this->db->transaction(function () use ($propertyId, $observations, $runId, $latest): int {
            $stored = 0;
            foreach ($observations as $o) {
                $last = $latest[$o->key()] ?? null;
                if ($last !== null && $last->fields->equals($o->fields)) {
                    continue;
                }
                $this->db->insert(self::TABLE, [
                    'property_code' => $propertyId,
                    'provider' => $o->provider->value,
                    'room_code' => $o->roomCode,
                    'night' => DbValue::night($o->night),
                    'fingerprint' => $o->fields->fingerprint(),
                    'fields' => DbValue::json($o->fields->all()),
                    'origin' => $o->origin,
                    'run_id' => $runId,
                    'observed_at' => DbValue::instant($o->observedAt),
                ]);
                ++$stored;
            }

            return $stored;
        });
    }

    /**
     * The latest observation of every room night of the property between two nights.
     *
     * @return array<string, Observation> Observation::key() => observation
     */
    public function latest(string $propertyId, DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $rows = $this->db->select(
            sprintf('SELECT * FROM %s WHERE property_code = ? AND night >= ? AND night <= ? ORDER BY observed_at, id', $this->db->table(self::TABLE)),
            [$propertyId, DbValue::night($from), DbValue::night($to)]
        );
        $latest = [];
        foreach ($rows as $row) {
            $observation = new Observation(
                Provider::from((string) $row['provider']),
                (string) $row['room_code'],
                DbValue::requireNight($row['night']),
                new NightFields(DbValue::toArray($row['fields'])),
                DbValue::requireInstant($row['observed_at']),
                (string) $row['origin'],
            );
            $latest[$observation->key()] = $observation;
        }

        return $latest;
    }

    /**
     * Deletes the observations of nights before $before.
     */
    public function purgeBefore(string $propertyId, DateTimeImmutable $before): int
    {
        return $this->db->execute(
            sprintf('DELETE FROM %s WHERE property_code = ? AND night < ?', $this->db->table(self::TABLE)),
            [$propertyId, DbValue::night($before)]
        );
    }
}
