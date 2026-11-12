<?php

declare(strict_types=1);

namespace Ritmo\Tests\Unit\Infrastructure\Lock;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Ritmo\Infrastructure\Db\PdoDatabase;
use Ritmo\Infrastructure\Lock\DbLock;
use Ritmo\Infrastructure\Lock\LockHandle;
use Ritmo\Tests\Unit\Infrastructure\Support\ManualClock;
use Ritmo\Tests\Unit\Infrastructure\Support\MigratedDatabase;

final class DbLockTest extends TestCase
{
    private PdoDatabase $db;

    private ManualClock $clock;

    private DbLock $locks;

    protected function setUp(): void
    {
        $this->db = MigratedDatabase::create();
        $this->clock = new ManualClock('2026-11-11T10:53:00Z');
        $this->locks = new DbLock($this->db, $this->clock);
    }

    public function testOnlyOneHolderAtATime(): void
    {
        $first = $this->locks->acquire('run:inn-1', 300);

        self::assertInstanceOf(LockHandle::class, $first);
        self::assertSame('run:inn-1', $first->key);
        self::assertNull($this->locks->acquire('run:inn-1', 300), 'the lock is held');
        self::assertNull((new DbLock($this->db, $this->clock))->acquire('run:inn-1', 300), 'also for another process');
        self::assertTrue($this->locks->isLocked('run:inn-1'));
    }

    public function testKeysAreIndependent(): void
    {
        self::assertNotNull($this->locks->acquire('run:inn-1', 300));
        self::assertNotNull($this->locks->acquire('run:inn-2', 300));
        self::assertFalse($this->locks->isLocked('run:inn-3'));
    }

    public function testAReleasedLockCanBeTakenAgain(): void
    {
        $first = $this->locks->acquire('run:inn-1', 300);
        self::assertNotNull($first);

        $this->locks->release($first);

        self::assertFalse($this->locks->isLocked('run:inn-1'));
        $second = $this->locks->acquire('run:inn-1', 300);
        self::assertNotNull($second);
        self::assertNotSame($first->owner, $second->owner);
    }

    public function testALockExpiresSoADeadProcessBlocksNobodyForLong(): void
    {
        $dead = $this->locks->acquire('run:inn-1', 300);
        self::assertNotNull($dead);

        $this->clock->advance(299);
        self::assertNull($this->locks->acquire('run:inn-1', 300), 'one second before it expires');
        self::assertTrue($this->locks->isLocked('run:inn-1'));

        $this->clock->advance(1);
        self::assertFalse($this->locks->isLocked('run:inn-1'), 'expired');
        $next = $this->locks->acquire('run:inn-1', 300);
        self::assertNotNull($next);

        self::assertFalse($this->locks->heartbeat($dead, 300), 'the old holder learns it lost the lock');
        $this->locks->release($dead);
        self::assertTrue($this->locks->isLocked('run:inn-1'), 'and cannot release the lock of the new holder');
        self::assertTrue($this->locks->heartbeat($next, 300));
    }

    public function testAHeartbeatKeepsTheLockAlive(): void
    {
        $handle = $this->locks->acquire('run:inn-1', 60);
        self::assertNotNull($handle);

        for ($beat = 0; $beat < 5; ++$beat) {
            $this->clock->advance(50);
            self::assertTrue($this->locks->heartbeat($handle, 60));
        }

        self::assertNull($this->locks->acquire('run:inn-1', 60), 'still held after 250 seconds');
        $holder = $this->locks->holder('run:inn-1');
        self::assertSame('2026-11-11T10:53:00+00:00', $holder['acquired_at']->format(DATE_ATOM));
        self::assertSame('2026-11-11T10:57:10+00:00', $holder['heartbeat_at']->format(DATE_ATOM));
        self::assertSame('2026-11-11T10:58:10+00:00', $holder['expires_at']->format(DATE_ATOM));

        $this->clock->advance(60);
        self::assertNotNull($this->locks->acquire('run:inn-1', 60), 'without a heartbeat it expires');
    }

    public function testAHeartbeatInTheSameSecondStillConfirmsTheLock(): void
    {
        $handle = $this->locks->acquire('run:inn-1', 60);
        self::assertNotNull($handle);

        self::assertTrue($this->locks->heartbeat($handle, 60));
        self::assertTrue($this->locks->heartbeat($handle, 60));
    }

    public function testAHeartbeatAfterReleaseOrWithAForgedHandleFails(): void
    {
        $handle = $this->locks->acquire('run:inn-1', 60);
        self::assertNotNull($handle);

        self::assertFalse($this->locks->heartbeat(new LockHandle('run:inn-1', 'someone-else'), 60));
        $this->locks->release(new LockHandle('run:inn-1', 'someone-else'));
        self::assertTrue($this->locks->isLocked('run:inn-1'));

        $this->locks->release($handle);
        self::assertFalse($this->locks->heartbeat($handle, 60));
        self::assertNull($this->locks->holder('run:inn-1'));
    }

    public function testTheHolderIsNotToldWhoOwnsTheLock(): void
    {
        $this->locks->acquire('run:inn-1', 60);

        self::assertSame(['acquired_at', 'heartbeat_at', 'expires_at'], array_keys((array) $this->locks->holder('run:inn-1')), 'the owner token stays private to its handle');
    }

    public function testKeysAndLifetimesAreValidated(): void
    {
        foreach ([
            fn () => $this->locks->acquire('', 60),
            fn () => $this->locks->acquire('has space', 60),
            fn () => $this->locks->acquire(str_repeat('k', 191), 60),
            fn () => $this->locks->acquire('run:inn-1', 0),
        ] as $call) {
            try {
                $call();
                self::fail('The call must be refused.');
            } catch (InvalidArgumentException) {
            }
        }

        self::assertSame('0', $this->db->selectValue('SELECT COUNT(*) FROM wp_ritmo_locks'), 'a refused lock leaves nothing behind');
    }
}
