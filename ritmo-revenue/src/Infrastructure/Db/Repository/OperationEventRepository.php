<?php

declare(strict_types=1);

namespace Ritmo\Infrastructure\Db\Repository;

use DateTimeImmutable;
use InvalidArgumentException;
use Ritmo\Infrastructure\Db\Database;
use Ritmo\Infrastructure\Db\DbValue;

/**
 * What people and the plugin did to the operation of a property (operations spec O41): mode
 * changes, approvals and refusals, the emergency switch, releases of a cancellation wave or of a
 * frozen night. Kept in the change log with who, when and why, without a provider (the night
 * changes are ChangeLogRepository's).
 */
final class OperationEventRepository
{
    public const MODE = 'mode';
    public const APPROVAL = 'approval';
    public const EMERGENCY = 'emergency';
    public const WAVE_RELEASE = 'wave_release';
    public const FREEZE_RELEASE = 'freeze_release';
    public const UNDO = 'undo_request';
    /** The listing values (minimum, base, maximum) read differ from the stored ones: the stored follow. */
    public const LISTING = 'listing_values';

    private const KINDS = [self::MODE, self::APPROVAL, self::EMERGENCY, self::WAVE_RELEASE, self::FREEZE_RELEASE, self::UNDO, self::LISTING];

    private const TABLE = 'change_log';

    public function __construct(private readonly Database $db)
    {
    }

    /**
     * @param string $actor "user:<id>" or "system"
     *
     * @throws InvalidArgumentException on an unknown kind
     */
    public function record(string $propertyId, string $kind, string $from, string $to, string $reason, string $actor, DateTimeImmutable $at, ?int $runId = null): int
    {
        if (!in_array($kind, self::KINDS, true)) {
            throw new InvalidArgumentException(sprintf('Unknown operation event "%s".', $kind));
        }

        return $this->db->insert(self::TABLE, [
            'run_id' => $runId,
            'property_code' => $propertyId,
            'provider' => '',
            'field' => $kind,
            'old_value' => DbValue::json(['v' => DbValue::clip($from, 190)]),
            'new_value' => DbValue::json(['v' => DbValue::clip($to, 190)]),
            'reason' => DbValue::json(['code' => $kind, 'text' => DbValue::clip($reason, 255)]),
            'actor' => DbValue::clip($actor, 64),
            'created_at' => DbValue::instant($at),
        ]);
    }

    /**
     * The latest events of a property, newest first.
     *
     * @return list<array{id: int, kind: string, from: string, to: string, reason: string, actor: string, at: DateTimeImmutable, run_id: int|null}>
     */
    public function latest(string $propertyId, ?string $kind = null, int $limit = 50): array
    {
        $sql = sprintf("SELECT * FROM %s WHERE provider = '' AND property_code = ?", $this->db->table(self::TABLE));
        $params = [$propertyId];
        if ($kind !== null) {
            $sql .= ' AND field = ?';
            $params[] = $kind;
        }
        $params[] = max(1, min(500, $limit));

        return array_map(self::event(...), $this->db->select($sql . ' ORDER BY created_at DESC, id DESC LIMIT ?', $params));
    }

    /**
     * The latest event of a kind that ended in $to (the last switch to a mode, say).
     *
     * @return array{id: int, kind: string, from: string, to: string, reason: string, actor: string, at: DateTimeImmutable, run_id: int|null}|null
     */
    public function lastTo(string $propertyId, string $kind, string $to): ?array
    {
        foreach ($this->latest($propertyId, $kind, 500) as $event) {
            if ($event['to'] === $to) {
                return $event;
            }
        }

        return null;
    }

    /**
     * @param array<string, string|null> $row
     *
     * @return array{id: int, kind: string, from: string, to: string, reason: string, actor: string, at: DateTimeImmutable, run_id: int|null}
     */
    private static function event(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'kind' => (string) $row['field'],
            'from' => (string) (DbValue::toArray($row['old_value'])['v'] ?? ''),
            'to' => (string) (DbValue::toArray($row['new_value'])['v'] ?? ''),
            'reason' => (string) (DbValue::toArray($row['reason'])['text'] ?? ''),
            'actor' => (string) $row['actor'],
            'at' => DbValue::requireInstant($row['created_at']),
            'run_id' => DbValue::nullableInt($row['run_id']),
        ];
    }
}
