<?php

declare(strict_types=1);

namespace Ritmo\Tests\Fakes;

use Ritmo\Contracts\Logger;

/**
 * A Logger that keeps every entry, so tests can read what was logged (and prove what was not).
 */
final class RecordingLogger implements Logger
{
    /** @var list<array{level: string, message: string, context: array<string, mixed>}> */
    private array $entries = [];

    public function log(string $level, string $message, array $context = []): void
    {
        $this->entries[] = ['level' => $level, 'message' => $message, 'context' => $context];
    }

    public function debug(string $message, array $context = []): void
    {
        $this->log(self::DEBUG, $message, $context);
    }

    public function info(string $message, array $context = []): void
    {
        $this->log(self::INFO, $message, $context);
    }

    public function warning(string $message, array $context = []): void
    {
        $this->log(self::WARNING, $message, $context);
    }

    public function error(string $message, array $context = []): void
    {
        $this->log(self::ERROR, $message, $context);
    }

    /**
     * @return list<array{level: string, message: string, context: array<string, mixed>}>
     */
    public function entries(?string $level = null): array
    {
        return array_values(array_filter($this->entries, static fn (array $entry): bool => $level === null || $entry['level'] === $level));
    }

    /**
     * Everything logged, as one text, to search for what must never be there.
     */
    public function dump(): string
    {
        return json_encode($this->entries, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
    }
}
