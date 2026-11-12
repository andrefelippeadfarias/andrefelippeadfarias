<?php

declare(strict_types=1);

namespace Ritmo\Tests\Unit\Infrastructure\Support;

use PDO;
use PDOException;

/**
 * A stand-in for WordPress's $wpdb with the members WpdbDatabase uses, backed by SQLite in
 * memory. Like WordPress's SQLite integration, it accepts the MySQL statements the plugin
 * sends and translates the few SQLite spells differently.
 */
final class FakeWpdb
{
    public string $prefix = 'wp_';

    public int $insert_id = 0;

    public string $last_error = '';

    /** @var list<string> every statement received, after prepare() */
    public array $queries = [];

    /** @var list<array{0: string, 1: list<mixed>}> every call of prepare() */
    public array $prepared = [];

    public readonly PDO $pdo;

    private bool $suppressErrors = false;

    public function __construct()
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }

    public function get_charset_collate(): string
    {
        return 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci';
    }

    public function suppress_errors(bool $suppress = true): bool
    {
        $previous = $this->suppressErrors;
        $this->suppressErrors = $suppress;

        return $previous;
    }

    /**
     * WordPress's prepare(): %s is quoted and escaped, %d is an integer, %f a float, %% a percent sign.
     */
    public function prepare(string $query, mixed ...$args): string
    {
        $this->prepared[] = [$query, array_values($args)];

        return (string) preg_replace_callback('/%([sdf%])/', function (array $match) use (&$args): string {
            if ($match[1] === '%') {
                return '%';
            }
            $value = array_shift($args);

            return match ($match[1]) {
                'd' => (string) (int) $value,
                'f' => sprintf('%F', (float) $value),
                default => $this->pdo->quote((string) $value),
            };
        }, $query);
    }

    /**
     * @return list<array<string, string|null>>|null
     */
    public function get_results(string $query, string $output = 'OBJECT'): ?array
    {
        $this->last_error = '';
        $this->queries[] = $query;
        try {
            // Every value is text. Like WordPress's SQLite driver, a float becomes text inside
            // PHP, so it is cut to the "precision" setting in force at this moment.
            return array_map(
                static fn (array $row): array => array_map(static fn (mixed $value): ?string => $value === null ? null : (string) $value, $row),
                $this->pdo->query($this->translate($query))->fetchAll(PDO::FETCH_ASSOC)
            );
        } catch (PDOException $failure) {
            $this->last_error = $failure->getMessage();

            return null;
        }
    }

    public function query(string $query): int|bool
    {
        $this->last_error = '';
        $this->queries[] = $query;
        try {
            $affected = $this->pdo->exec($this->translate($query));
        } catch (PDOException $failure) {
            $this->last_error = $failure->getMessage();

            return false;
        }
        if (preg_match('/^\s*(create|alter|truncate|drop)\s/i', $query) === 1) {
            return true;
        }
        if (preg_match('/^\s*(insert|replace)\s/i', $query) === 1) {
            $this->insert_id = (int) $this->pdo->lastInsertId();
        }

        return (int) $affected;
    }

    private function translate(string $query): string
    {
        $query = (string) preg_replace('/^\s*START TRANSACTION\s*$/i', 'BEGIN', $query);
        $query = (string) preg_replace('/^\s*INSERT IGNORE INTO/i', 'INSERT OR IGNORE INTO', $query);
        if (preg_match('/^(.*) ON DUPLICATE KEY UPDATE (.*)$/is', $query, $match) === 1) {
            $query = $match[1] . ' ON CONFLICT DO UPDATE SET ' . preg_replace('/VALUES\((\w+)\)/i', 'excluded.$1', $match[2]);
        }

        return $query;
    }
}
