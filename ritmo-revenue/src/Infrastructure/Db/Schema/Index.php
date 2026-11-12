<?php

declare(strict_types=1);

namespace Ritmo\Infrastructure\Db\Schema;

use InvalidArgumentException;
use Ritmo\Infrastructure\Db\Statement;

/**
 * An index of a plugin table. Its name is unique within the table.
 */
final class Index
{
    /**
     * @param list<string> $columns
     */
    private function __construct(
        public readonly string $name,
        public readonly array $columns,
        public readonly bool $unique,
    ) {
        Statement::identifier($name);
        if ($columns === []) {
            throw new InvalidArgumentException('An index needs at least one column.');
        }
        array_map(Statement::identifier(...), $columns);
    }

    public static function unique(string $name, string ...$columns): self
    {
        return new self($name, array_values($columns), true);
    }

    public static function plain(string $name, string ...$columns): self
    {
        return new self($name, array_values($columns), false);
    }
}
