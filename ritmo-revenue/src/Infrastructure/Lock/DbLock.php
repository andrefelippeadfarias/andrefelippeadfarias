<?php

declare(strict_types=1);

namespace Ritmo\Infrastructure\Lock;

use DateTimeImmutable;
use InvalidArgumentException;
use Ritmo\Domain\Support\Clock;
use Ritmo\Infrastructure\Db\Database;
use Ritmo\Infrastructure\Db\DbValue;

/**
 * Locks by key, kept in the database, so two processes of the same site never do the same
 * work at once (two runs of one property, for example).
 *
 * A lock expires: a process that dies leaves no lock behind for longer than its time to
 * live. Long work calls heartbeat() to keep its lock, and stops when heartbeat() says the
 * lock was lost. Times are kept to the second.
 */
final class DbLock
{
    private const TABLE = 'locks';

    public function __construct(private readonly Database $db, private readonly Clock $clock)
    {
    }

    /**
     * Takes the lock when it is free or expired.
     *
     * @param string $key        what is locked ("run:property-code"); letters, digits and "_.:-", up to 190 characters
     * @param int    $ttlSeconds how long the lock lives without a heartbeat
     *
     * @return LockHandle|null null when someone else holds the lock
     */
    public function acquire(string $key, int $ttlSeconds): ?LockHandle
    {
        $key = DbValue::word($key, 190);
        $now = $this->clock->now();
        $this->db->execute(
            sprintf('DELETE FROM %s WHERE lock_key = ? AND expires_at <= ?', $this->db->table(self::TABLE)),
            [$key, DbValue::instant($now)]
        );

        $owner = bin2hex(random_bytes(16));
        $taken = $this->db->insertIgnore(self::TABLE, [
            'lock_key' => $key,
            'owner' => $owner,
            'acquired_at' => DbValue::instant($now),
            'heartbeat_at' => DbValue::instant($now),
            'expires_at' => DbValue::instant($this->expiry($now, $ttlSeconds)),
        ]);

        return $taken ? new LockHandle($key, $owner) : null;
    }

    /**
     * Extends a lock for another $ttlSeconds from now.
     *
     * @return bool false when the lock is no longer held by this handle (it expired and was taken, or it was released): stop the work
     */
    public function heartbeat(LockHandle $handle, int $ttlSeconds): bool
    {
        $now = $this->clock->now();
        $this->db->update(
            self::TABLE,
            ['heartbeat_at' => DbValue::instant($now), 'expires_at' => DbValue::instant($this->expiry($now, $ttlSeconds))],
            ['lock_key' => $handle->key, 'owner' => $handle->owner]
        );

        // Asked again instead of trusting the row count, which is 0 on some databases when nothing changed.
        return $this->db->selectValue(
            sprintf('SELECT owner FROM %s WHERE lock_key = ?', $this->db->table(self::TABLE)),
            [$handle->key]
        ) === $handle->owner;
    }

    /**
     * Gives the lock back. Releasing a lock that was lost changes nothing.
     */
    public function release(LockHandle $handle): void
    {
        $this->db->delete(self::TABLE, ['lock_key' => $handle->key, 'owner' => $handle->owner]);
    }

    /**
     * Who holds a lock now; null when it is free or expired.
     *
     * @return array{acquired_at: DateTimeImmutable, heartbeat_at: DateTimeImmutable, expires_at: DateTimeImmutable}|null
     */
    public function holder(string $key): ?array
    {
        $row = $this->db->selectRow(
            sprintf('SELECT acquired_at, heartbeat_at, expires_at FROM %s WHERE lock_key = ? AND expires_at > ?', $this->db->table(self::TABLE)),
            [$key, DbValue::instant($this->clock->now())]
        );
        if ($row === null) {
            return null;
        }

        return [
            'acquired_at' => DbValue::requireInstant($row['acquired_at']),
            'heartbeat_at' => DbValue::requireInstant($row['heartbeat_at']),
            'expires_at' => DbValue::requireInstant($row['expires_at']),
        ];
    }

    public function isLocked(string $key): bool
    {
        return $this->holder($key) !== null;
    }

    private function expiry(DateTimeImmutable $now, int $ttlSeconds): DateTimeImmutable
    {
        if ($ttlSeconds < 1) {
            throw new InvalidArgumentException('A lock lives at least one second.');
        }

        return $now->modify(sprintf('+%d seconds', $ttlSeconds));
    }
}
