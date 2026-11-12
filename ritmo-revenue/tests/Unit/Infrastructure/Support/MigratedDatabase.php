<?php

declare(strict_types=1);

namespace Ritmo\Tests\Unit\Infrastructure\Support;

use Ritmo\Infrastructure\Db\Migration\Migrator;
use Ritmo\Infrastructure\Db\PdoDatabase;
use Ritmo\Infrastructure\Store\ArrayStore;

/**
 * A private in-memory SQLite database with every plugin table, for one test.
 */
final class MigratedDatabase
{
    public static function create(): PdoDatabase
    {
        $db = PdoDatabase::inMemory();
        Migrator::standard($db, new ArrayStore())->migrate();

        return $db;
    }
}
