<?php

declare(strict_types=1);

namespace Ritmo\Infrastructure\Db\Repository;

use DateTimeImmutable;
use Ritmo\Domain\Config\InvalidConfig;
use Ritmo\Domain\Config\RuleSet;
use Ritmo\Domain\Support\Clock;
use Ritmo\Infrastructure\Db\Database;
use Ritmo\Infrastructure\Db\DbValue;

/**
 * The versions of the site's rule set. A version is written once and never edited or deleted:
 * changing a rule saves a new version, and going back saves the old rules as a new version.
 * This class has no method that updates or removes a row, on purpose.
 */
final class RuleSetRepository
{
    private const TABLE = 'rulesets';

    public function __construct(private readonly Database $db, private readonly Clock $clock)
    {
    }

    /**
     * Saves the rules as the new current version and returns its number. Saving rules equal
     * to the current version creates nothing and returns the current number.
     *
     * @param string $note   what changed, shown in the history
     * @param int    $userId WordPress user who saved; 0 for the system
     */
    public function save(RuleSet $rules, string $note = '', int $userId = 0): int
    {
        return $this->db->transaction(function () use ($rules, $note, $userId): int {
            $latest = $this->db->selectRow(sprintf('SELECT version, hash FROM %s ORDER BY version DESC LIMIT 1', $this->db->table(self::TABLE)));
            if ($latest !== null && $latest['hash'] === $rules->hash()) {
                return (int) $latest['version'];
            }

            $version = $latest === null ? 1 : (int) $latest['version'] + 1;
            $this->db->insert(self::TABLE, [
                'version' => $version,
                'hash' => $rules->hash(),
                'rules' => DbValue::json($rules->toArray()),
                'note' => DbValue::clip($note, 190),
                'created_by' => max(0, $userId),
                'created_at' => DbValue::instant($this->clock->now()),
            ]);

            return $version;
        });
    }

    public function currentVersion(): ?int
    {
        return DbValue::nullableInt($this->db->selectValue(sprintf('SELECT MAX(version) FROM %s', $this->db->table(self::TABLE))));
    }

    /**
     * The rules in force; null before the first save.
     *
     * @throws InvalidConfig when the stored rules no longer pass validation
     */
    public function current(): ?RuleSet
    {
        $version = $this->currentVersion();

        return $version === null ? null : $this->find($version);
    }

    /**
     * A stored version; the settings added by later versions of the plugin take their neutral
     * defaults (RuleSet::fromStored).
     *
     * @throws InvalidConfig when the stored rules no longer pass validation
     */
    public function find(int $version): ?RuleSet
    {
        $stored = $this->db->selectValue(sprintf('SELECT rules FROM %s WHERE version = ?', $this->db->table(self::TABLE)), [$version]);

        return $stored === null ? null : RuleSet::fromStored(DbValue::toArray($stored));
    }

    /**
     * After an update of the plugin: when the rules in force miss settings added since, saves
     * them completed with the neutral defaults as a new version (nothing when already complete).
     *
     * @return int|null the version saved, or null when nothing changed
     *
     * @throws InvalidConfig when the stored rules do not pass validation even completed
     */
    public function upgradeCurrent(): ?int
    {
        $version = $this->currentVersion();
        if ($version === null) {
            return null;
        }
        $stored = DbValue::toArray($this->db->selectValue(sprintf('SELECT rules FROM %s WHERE version = ?', $this->db->table(self::TABLE)), [$version]));
        $completed = RuleSet::fromStored($stored);
        if ($completed->toArray() === $stored) {
            return null;
        }
        $saved = $this->save($completed, 'Completed with the neutral defaults of the settings added by an update');

        return $saved === $version ? null : $saved;
    }

    /**
     * The versions, newest first, without the rules themselves.
     *
     * @return list<array{version: int, hash: string, note: string, created_by: int, created_at: DateTimeImmutable}>
     */
    public function history(int $limit = 50): array
    {
        $rows = $this->db->select(
            sprintf('SELECT version, hash, note, created_by, created_at FROM %s ORDER BY version DESC LIMIT ?', $this->db->table(self::TABLE)),
            [max(1, $limit)]
        );

        return array_map(static fn (array $row): array => [
            'version' => (int) $row['version'],
            'hash' => (string) $row['hash'],
            'note' => (string) $row['note'],
            'created_by' => (int) $row['created_by'],
            'created_at' => DbValue::requireInstant($row['created_at']),
        ], $rows);
    }
}
