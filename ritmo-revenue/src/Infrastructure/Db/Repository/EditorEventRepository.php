<?php

declare(strict_types=1);

namespace Ritmo\Infrastructure\Db\Repository;

use DateTimeImmutable;
use InvalidArgumentException;
use Ritmo\Infrastructure\Db\Database;
use Ritmo\Infrastructure\Db\DbValue;

/**
 * Who changed local configuration, and when: a new rule-set version, an owner order created or
 * ended, a competitor, a market price import. Written to the change log in the same shape as
 * the operation events (empty provider, the kind in "field"), so the events screen lists them
 * next to mode changes and approvals. Nothing here writes to an external system.
 */
final class EditorEventRepository
{
    public const RULES_VERSION = 'rules_version';
    public const ORDER = 'order';
    public const COMPETITOR = 'competitor';
    public const MARKET_IMPORT = 'market_import';

    private const KINDS = [self::RULES_VERSION, self::ORDER, self::COMPETITOR, self::MARKET_IMPORT];

    private const TABLE = 'change_log';

    public function __construct(private readonly Database $db)
    {
    }

    /**
     * @param string               $actor   "user:<id>" or "system"
     * @param array<string, mixed> $details plain values shown on the events screen (no guest data)
     *
     * @throws InvalidArgumentException on an unknown kind
     */
    public function record(string $propertyId, string $kind, string $from, string $to, string $reason, string $actor, DateTimeImmutable $at, array $details = []): int
    {
        if (!in_array($kind, self::KINDS, true)) {
            throw new InvalidArgumentException(sprintf('Unknown configuration event "%s".', $kind));
        }

        return $this->db->insert(self::TABLE, [
            'run_id' => null,
            'property_code' => $propertyId,
            'provider' => '',
            'field' => $kind,
            'old_value' => DbValue::json(['v' => DbValue::clip($from, 190)]),
            'new_value' => DbValue::json(['v' => DbValue::clip($to, 190)]),
            'reason' => DbValue::json(['code' => $kind, 'text' => DbValue::clip($reason, 255), 'details' => $details]),
            'actor' => DbValue::clip($actor, 64),
            'created_at' => DbValue::instant($at),
        ]);
    }

    /**
     * The latest events of the given kinds for a property, newest first.
     *
     * @param list<string> $kinds
     *
     * @return list<array{id: int, kind: string, from: string, to: string, reason: string, details: array<string, mixed>, actor: string, at: DateTimeImmutable}>
     */
    public function latest(string $propertyId, array $kinds, int $limit = 50): array
    {
        $kinds = array_values(array_intersect($kinds, self::KINDS));
        if ($kinds === []) {
            return [];
        }
        $rows = $this->db->select(
            sprintf("SELECT * FROM %s WHERE provider = '' AND property_code = ? AND field IN (%s) ORDER BY created_at DESC, id DESC LIMIT ?", $this->db->table(self::TABLE), implode(', ', array_fill(0, count($kinds), '?'))),
            [$propertyId, ...$kinds, max(1, min(500, $limit))]
        );

        return array_map(static function (array $row): array {
            $reason = DbValue::toArray($row['reason']);

            return [
                'id' => (int) $row['id'],
                'kind' => (string) $row['field'],
                'from' => (string) (DbValue::toArray($row['old_value'])['v'] ?? ''),
                'to' => (string) (DbValue::toArray($row['new_value'])['v'] ?? ''),
                'reason' => (string) ($reason['text'] ?? ''),
                'details' => is_array($reason['details'] ?? null) ? $reason['details'] : [],
                'actor' => (string) $row['actor'],
                'at' => DbValue::requireInstant($row['created_at']),
            ];
        }, $rows);
    }
}
