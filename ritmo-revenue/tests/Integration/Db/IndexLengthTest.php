<?php

declare(strict_types=1);

namespace Ritmo\Tests\Integration\Db;

use PHPUnit\Framework\TestCase;
use Ritmo\Infrastructure\Db\Migration\Migrations;
use Ritmo\Infrastructure\Db\Schema\ColumnType;

/**
 * Every key a migration creates fits 767 bytes in utf8mb4 (4 bytes a character), the limit of
 * MySQL 5.6 and MariaDB before 10.2.2 with COMPACT rows, so the tables are created there too.
 */
final class IndexLengthTest extends TestCase
{
    public function testEveryKeyOfEveryMigrationFits767BytesInUtf8mb4(): void
    {
        $over = [];
        foreach (Migrations::all() as $migration) {
            foreach ($migration->tables() as $table) {
                $lengths = [];
                foreach ($table->columns as $column) {
                    $lengths[$column->name] = match ($column->type) {
                        ColumnType::Text => $column->length * 4,
                        default => 8,
                    };
                }
                foreach ($table->indexes as $index) {
                    $bytes = array_sum(array_map(static fn (string $c): int => $lengths[$c] ?? 0, $index->columns));
                    if ($bytes > 767) {
                        $over[] = sprintf('%d %s.%s: %d bytes', $migration->version(), $table->name, $index->name, $bytes);
                    }
                }
            }
        }

        self::assertSame([], $over);
    }
}
