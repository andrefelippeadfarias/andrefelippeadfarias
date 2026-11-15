<?php

declare(strict_types=1);

namespace Ritmo\Tests\Integration\Schedule;

use PDO;
use PHPUnit\Framework\TestCase;
use Ritmo\Application\Schedule\LockedRunLauncher;
use Ritmo\Domain\Model\Property;
use Ritmo\Infrastructure\Db\Migration\Migrations;
use Ritmo\Infrastructure\Db\Migration\Migrator;
use Ritmo\Infrastructure\Db\PdoDatabase;
use Ritmo\Infrastructure\Db\Repository\AlertRepository;
use Ritmo\Infrastructure\Db\Repository\PropertyRepository;
use Ritmo\Infrastructure\Db\Repository\RunRepository;
use Ritmo\Infrastructure\Lock\DbLock;
use Ritmo\Infrastructure\Store\ArrayStore;
use Ritmo\Tests\Fakes\MovableClock;

/**
 * A run that PHP really stops (out of memory) in the middle of a stage, inside the stage's
 * transaction, in a process of its own: the run is closed as failed, the lock is given back and
 * the site is told, all of it committed (a direct call of closeAfterFatal cannot show this).
 */
final class RunStoppedByPhpTest extends TestCase
{
    private string $file = '';

    protected function setUp(): void
    {
        $this->file = (string) tempnam(sys_get_temp_dir(), 'ritmo-oom-');
        $db = $this->db();
        (new Migrator($db, new ArrayStore(), Migrations::all()))->migrate();
        (new PropertyRepository($db, new MovableClock('2026-11-12T10:31:00Z')))->save(new Property('inn', 'Sample inn', 'America/Sao_Paulo', 'BRL'));
    }

    protected function tearDown(): void
    {
        foreach ([$this->file, $this->file . '-journal'] as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    public function testARunOutOfMemoryInsideATransactionIsClosedItsLockFreedAndTheSiteTold(): void
    {
        $output = [];
        $exit = 0;
        exec(escapeshellarg(PHP_BINARY) . ' -d memory_limit=48M -d display_errors=stderr ' . escapeshellarg(__DIR__ . '/run-out-of-memory.php') . ' ' . escapeshellarg($this->file) . ' 2>&1', $output, $exit);
        $text = implode("\n", $output);
        self::assertNotSame(0, $exit, $text);
        self::assertStringContainsString('Allowed memory size', $text);
        self::assertStringNotContainsString('not reached', $text);

        $db = $this->db();
        $clock = new MovableClock('2026-11-12T10:31:00Z');
        $runs = $db->select(sprintf('SELECT id, status, error FROM %s', $db->table('runs')));
        self::assertCount(1, $runs);
        self::assertSame(RunRepository::FAILED, $runs[0]['status'], 'the run is not left "running"');
        self::assertStringContainsString('ran out of memory', (string) $runs[0]['error']);
        self::assertFalse((new DbLock($db, $clock))->isLocked(LockedRunLauncher::lockKey('inn')), 'no wait of ten minutes for the lock');
        $alerts = (new AlertRepository($db, $clock))->unresolved('inn');
        self::assertSame(['run_stopped'], array_column($alerts, 'code'));
        self::assertSame((int) $runs[0]['id'], $alerts[0]['run_id']);
    }

    private function db(): PdoDatabase
    {
        return new PdoDatabase(new PDO('sqlite:' . $this->file));
    }
}
