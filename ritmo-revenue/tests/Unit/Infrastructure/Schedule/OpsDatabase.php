<?php

declare(strict_types=1);

namespace Ritmo\Tests\Unit\Infrastructure\Schedule;

use Ritmo\Infrastructure\Db\Migration\DraftOps;
use Ritmo\Infrastructure\Db\Migration\Migrations;
use Ritmo\Infrastructure\Db\Migration\Migrator;
use Ritmo\Infrastructure\Db\PdoDatabase;
use Ritmo\Infrastructure\Store\ArrayStore;

/**
 * A private in-memory SQLite database with every plugin table and the draft tables of the
 * operations area (DraftOps, numbered after the existing migrations).
 */
final class OpsDatabase
{
    public static function create(): PdoDatabase
    {
        $db = PdoDatabase::inMemory();
        (new Migrator($db, new ArrayStore(), [...Migrations::all(), new DraftOps()]))->migrate();

        return $db;
    }
}
