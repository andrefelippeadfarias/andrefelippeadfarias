<?php

declare(strict_types=1);

namespace Ritmo\Infrastructure\Db\Migration;

use Ritmo\Infrastructure\Db\Schema\Column;
use Ritmo\Infrastructure\Db\Schema\Index;
use Ritmo\Infrastructure\Db\Schema\Table;

/**
 * The plugin's own plumbing: locks and the log.
 */
final class Migration0004Housekeeping implements Migration
{
    public function version(): int
    {
        return 4;
    }

    public function description(): string
    {
        return 'Housekeeping tables: locks and log.';
    }

    public function tables(): array
    {
        return [
            new Table('locks', [
                Column::text('lock_key', 190),
                Column::text('owner', 64),
                Column::dateTime('acquired_at'),
                Column::dateTime('heartbeat_at'),
                Column::dateTime('expires_at'),
            ], [Index::unique('lock_key', 'lock_key')]),

            new Table('log', [
                Column::text('level', 16),
                Column::longText('message'),
                Column::longText('context'),
                Column::dateTime('created_at'),
            ], [Index::plain('created', 'created_at'), Index::plain('level', 'level', 'created_at')]),
        ];
    }
}
