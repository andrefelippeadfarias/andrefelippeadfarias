<?php

declare(strict_types=1);

namespace Ritmo\Infrastructure\Log;

use DateTimeImmutable;
use Ritmo\Contracts\Logger;
use Ritmo\Domain\Support\Clock;
use Ritmo\Infrastructure\Db\Database;
use Ritmo\Infrastructure\Db\DatabaseException;
use Ritmo\Infrastructure\Db\DbValue;

/**
 * The plugin's log, kept in its own table. Entries below the minimum level are dropped and
 * every entry passes through the LogSanitizer before it is stored.
 *
 * Logging never breaks the work being logged: when the table cannot be written, the entry
 * goes to PHP's error log instead.
 */
final class DbLogger implements Logger
{
    use LogsByLevel;

    private const TABLE = 'log';

    /**
     * @param string $minimumLevel lowest level kept; Logger::DEBUG to keep everything
     */
    public function __construct(
        private readonly Database $db,
        private readonly Clock $clock,
        private readonly LogSanitizer $sanitizer,
        private readonly string $minimumLevel = Logger::INFO,
    ) {
        LogLevel::rank($minimumLevel);
    }

    public function log(string $level, string $message, array $context = []): void
    {
        if (!LogLevel::reaches($level, $this->minimumLevel)) {
            return;
        }

        $message = $this->sanitizer->message($message);
        try {
            $this->db->insert(self::TABLE, [
                'level' => $level,
                'message' => $message,
                'context' => DbValue::json($this->sanitizer->context($context)),
                'created_at' => DbValue::instant($this->clock->now()),
            ]);
        } catch (DatabaseException $failure) {
            error_log(sprintf('Ritmo Revenue [%s] %s (the log table could not be written: %s)', $level, $message, $failure->getMessage()));
        }
    }

    /**
     * The latest entries, newest first.
     *
     * @param string $minimumLevel only entries of this level or above
     *
     * @return list<array{id: int, level: string, message: string, context: array<array-key, mixed>, created_at: DateTimeImmutable}>
     */
    public function recent(int $limit = 100, string $minimumLevel = Logger::DEBUG): array
    {
        $levels = array_values(array_filter(
            [Logger::DEBUG, Logger::INFO, Logger::WARNING, Logger::ERROR],
            static fn (string $level): bool => LogLevel::reaches($level, $minimumLevel)
        ));
        $rows = $this->db->select(
            sprintf(
                'SELECT * FROM %s WHERE level IN (%s) ORDER BY id DESC LIMIT ?',
                $this->db->table(self::TABLE),
                implode(', ', array_fill(0, count($levels), '?'))
            ),
            [...$levels, max(1, $limit)]
        );

        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'level' => (string) $row['level'],
            'message' => (string) $row['message'],
            'context' => DbValue::toArray($row['context']),
            'created_at' => DbValue::requireInstant($row['created_at']),
        ], $rows);
    }

    /**
     * Removes the entries older than an instant.
     *
     * @return int rows removed
     */
    public function purgeBefore(DateTimeImmutable $instant): int
    {
        return $this->db->execute(
            sprintf('DELETE FROM %s WHERE created_at < ?', $this->db->table(self::TABLE)),
            [DbValue::instant($instant)]
        );
    }
}
