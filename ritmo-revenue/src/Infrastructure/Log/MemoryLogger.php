<?php

declare(strict_types=1);

namespace Ritmo\Infrastructure\Log;

use Ritmo\Contracts\Logger;

/**
 * Logger that keeps its entries in memory, for tests and for code that runs before the
 * database is ready. Entries are sanitized exactly as the stored log would keep them.
 */
final class MemoryLogger implements Logger
{
    use LogsByLevel;

    /** @var list<array{level: string, message: string, context: array<array-key, mixed>}> */
    private array $entries = [];

    private readonly LogSanitizer $sanitizer;

    public function __construct(?LogSanitizer $sanitizer = null, private readonly string $minimumLevel = Logger::DEBUG)
    {
        LogLevel::rank($minimumLevel);
        $this->sanitizer = $sanitizer ?? new LogSanitizer();
    }

    public function log(string $level, string $message, array $context = []): void
    {
        if (LogLevel::reaches($level, $this->minimumLevel)) {
            $this->entries[] = [
                'level' => $level,
                'message' => $this->sanitizer->message($message),
                'context' => $this->sanitizer->context($context),
            ];
        }
    }

    /**
     * @param string|null $level only the entries of this level; null for all
     *
     * @return list<array{level: string, message: string, context: array<array-key, mixed>}>
     */
    public function entries(?string $level = null): array
    {
        return $level === null
            ? $this->entries
            : array_values(array_filter($this->entries, static fn (array $entry): bool => $entry['level'] === $level));
    }
}
