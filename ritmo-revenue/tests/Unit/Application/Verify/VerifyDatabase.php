<?php

declare(strict_types=1);

namespace Ritmo\Tests\Unit\Application\Verify;

use Ritmo\Infrastructure\Db\Migration\DraftVerify;
use Ritmo\Infrastructure\Db\Migration\Migrations;
use Ritmo\Infrastructure\Db\Migration\Migrator;
use Ritmo\Infrastructure\Db\PdoDatabase;
use Ritmo\Infrastructure\Store\ArrayStore;

/**
 * A private in-memory SQLite database with every plugin table and the draft tables of the
 * confirmation and approval area (DraftVerify, numbered after the existing migrations).
 */
final class VerifyDatabase
{
    public static function create(): PdoDatabase
    {
        $db = PdoDatabase::inMemory();
        (new Migrator($db, new ArrayStore(), [...Migrations::all(), new DraftVerify()]))->migrate();

        return $db;
    }
}
