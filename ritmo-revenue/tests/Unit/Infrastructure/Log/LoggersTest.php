<?php

declare(strict_types=1);

namespace Ritmo\Tests\Unit\Infrastructure\Log;

use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Ritmo\Contracts\Logger;
use Ritmo\Infrastructure\Db\PdoDatabase;
use Ritmo\Infrastructure\Log\DbLogger;
use Ritmo\Infrastructure\Log\LogLevel;
use Ritmo\Infrastructure\Log\LogSanitizer;
use Ritmo\Infrastructure\Log\MemoryLogger;
use Ritmo\Tests\Unit\Infrastructure\Support\ManualClock;
use Ritmo\Tests\Unit\Infrastructure\Support\MigratedDatabase;

final class LoggersTest extends TestCase
{
    private PdoDatabase $db;

    private ManualClock $clock;

    protected function setUp(): void
    {
        $this->db = MigratedDatabase::create();
        $this->clock = new ManualClock('2026-11-11T10:53:00Z');
    }

    public function testEntriesAreStoredWithLevelContextAndTime(): void
    {
        $logger = new DbLogger($this->db, $this->clock, new LogSanitizer());

        $logger->info('Run started.', ['run_id' => 7, 'property_id' => 'inn-1']);
        $this->clock->advance(5);
        $logger->error('The PMS did not answer.', ['provider' => 'pms', 'http_status' => 503, 'attempt' => 2]);

        $entries = $logger->recent();
        self::assertSame(['The PMS did not answer.', 'Run started.'], array_column($entries, 'message'));
        self::assertSame(Logger::ERROR, $entries[0]['level']);
        self::assertSame(['provider' => 'pms', 'http_status' => 503, 'attempt' => 2], $entries[0]['context']);
        self::assertSame('2026-11-11T10:53:05+00:00', $entries[0]['created_at']->format(DATE_ATOM));
        self::assertSame(['run_id' => 7, 'property_id' => 'inn-1'], $entries[1]['context']);
    }

    public function testDebugIsOffByDefaultAndTheMinimumLevelIsConfigurable(): void
    {
        $default = new DbLogger($this->db, $this->clock, new LogSanitizer());
        $default->debug('Detail.');
        $default->info('Info.');
        $default->warning('Warning.');
        $default->error('Error.');
        self::assertSame(['Error.', 'Warning.', 'Info.'], array_column($default->recent(), 'message'));

        $quiet = new DbLogger($this->db, $this->clock, new LogSanitizer(), Logger::ERROR);
        $quiet->warning('Dropped.');
        $verbose = new DbLogger($this->db, $this->clock, new LogSanitizer(), Logger::DEBUG);
        $verbose->debug('Kept.');

        $messages = array_column($default->recent(), 'message');
        self::assertNotContains('Dropped.', $messages);
        self::assertContains('Kept.', $messages);
        self::assertSame(['Error.', 'Warning.'], array_column($default->recent(100, Logger::WARNING), 'message'));
        self::assertSame(['Kept.'], array_column($default->recent(1), 'message'));
    }

    public function testNothingSecretOrPersonalReachesTheTable(): void
    {
        $sanitizer = new LogSanitizer();
        $sanitizer->registerSecret('pk-live-3f9a1c77d2e04b55');
        $logger = new DbLogger($this->db, $this->clock, $sanitizer, Logger::DEBUG);

        $logger->warning('Request to https://api.pms.example/v2/bookings?token=abc123 failed with key pk-live-3f9a1c77d2e04b55.', [
            'headers' => ['X-API-Key' => 'pk-live-3f9a1c77d2e04b55', 'Accept' => 'application/json'],
            'body' => '[{"firstName":"Maria","lastName":"Example","email":"maria@example.com"}]',
            'bookings' => [['id' => 1, 'firstName' => 'Maria', 'lastName' => 'Example']],
            'guest_name' => 'Maria Example',
            'detail' => 'reply to maria@example.com',
            'http_status' => 500,
        ]);

        $stored = (string) json_encode($this->db->select('SELECT * FROM wp_ritmo_log'));
        foreach (['pk-live', 'abc123', 'Maria', 'Example', 'maria@'] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $stored);
        }
        $entry = $logger->recent()[0];
        self::assertSame('Request to https://api.pms.example/v2/bookings?token=[redacted] failed with key [redacted].', $entry['message']);
        self::assertSame(500, $entry['context']['http_status']);
        self::assertSame('application/json', $entry['context']['headers']['Accept']);
    }

    public function testOldEntriesCanBePurged(): void
    {
        $logger = new DbLogger($this->db, $this->clock, new LogSanitizer());
        $logger->info('Old.');
        $this->clock->advance(86400 * 40);
        $logger->info('New.');

        self::assertSame(1, $logger->purgeBefore(new DateTimeImmutable('2026-12-01T00:00:00Z')));
        self::assertSame(['New.'], array_column($logger->recent(), 'message'));
    }

    public function testAnUnknownLevelIsAProgrammingError(): void
    {
        $logger = new DbLogger($this->db, $this->clock, new LogSanitizer());

        try {
            new DbLogger($this->db, $this->clock, new LogSanitizer(), 'loud');
            self::fail('An unknown minimum level must be refused.');
        } catch (InvalidArgumentException) {
        }
        $this->expectException(InvalidArgumentException::class);
        $logger->log('critical', 'Not a level of this log.');
    }

    public function testWhenTheTableCannotBeWrittenTheWorkGoesOnAndTheEntryGoesToTheErrorLog(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'ritmo-log');
        self::assertIsString($file);
        $previous = ini_set('error_log', $file);
        try {
            $sanitizer = new LogSanitizer();
            $sanitizer->registerSecret('pk-live-3f9a1c77d2e04b55');
            $logger = new DbLogger(PdoDatabase::inMemory(), $this->clock, $sanitizer);   // no tables at all

            $logger->error('Could not reach the pricing system with pk-live-3f9a1c77d2e04b55.', ['attempt' => 1]);

            $written = (string) file_get_contents($file);
            self::assertStringContainsString('Ritmo Revenue [error] Could not reach the pricing system with [redacted].', $written);
            self::assertStringNotContainsString('pk-live', $written);
        } finally {
            ini_set('error_log', (string) $previous);
            unlink($file);
        }
    }

    public function testTheMemoryLoggerKeepsSanitizedEntriesByLevel(): void
    {
        $logger = new MemoryLogger();
        $logger->debug('Detail.', ['token' => 'abc123']);
        $logger->info('Info.');
        $logger->warning('Warning.', ['guest' => 'Maria']);
        $logger->error('Error.');

        self::assertCount(4, $logger->entries());
        self::assertSame([['level' => 'warning', 'message' => 'Warning.', 'context' => ['guest' => LogSanitizer::OMITTED]]], $logger->entries(Logger::WARNING));
        self::assertSame(LogSanitizer::REDACTED, $logger->entries(Logger::DEBUG)[0]['context']['token']);

        $quiet = new MemoryLogger(null, Logger::WARNING);
        $quiet->info('Dropped.');
        $quiet->error('Kept.');
        self::assertSame(['Kept.'], array_column($quiet->entries(), 'message'));
    }

    public function testLevelsAreOrdered(): void
    {
        self::assertSame([0, 1, 2, 3], array_map(LogLevel::rank(...), [Logger::DEBUG, Logger::INFO, Logger::WARNING, Logger::ERROR]));
        self::assertTrue(LogLevel::reaches(Logger::ERROR, Logger::WARNING));
        self::assertTrue(LogLevel::reaches(Logger::INFO, Logger::INFO));
        self::assertFalse(LogLevel::reaches(Logger::DEBUG, Logger::INFO));
    }
}
