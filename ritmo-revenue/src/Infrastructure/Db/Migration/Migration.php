<?php

declare(strict_types=1);

namespace Ritmo\Infrastructure\Db\Migration;

use Ritmo\Infrastructure\Db\Schema\Table;

/**
 * One numbered step of the plugin's database schema.
 *
 * A migration is declarative: it states the tables as they must be after it ran. Applying it
 * creates what is missing (tables, columns, indexes) and never drops or rewrites data, so it
 * can run again at any time. A later migration that changes a table states the whole table
 * again with the new columns, which must be nullable or carry a default.
 */
interface Migration
{
    /**
     * Position in the sequence, starting at 1. Never reused, never renumbered.
     */
    public function version(): int;

    /**
     * Short English description, for the log and the diagnostics screen.
     */
    public function description(): string;

    /**
     * @return list<Table>
     */
    public function tables(): array;
}
