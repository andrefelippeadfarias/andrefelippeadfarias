<?php

declare(strict_types=1);

namespace Ritmo\Infrastructure\Db\Migration;

use Ritmo\Infrastructure\Db\Schema\Column;
use Ritmo\Infrastructure\Db\Schema\Index;
use Ritmo\Infrastructure\Db\Schema\Table;

/**
 * Central screens (milestone M4).
 *
 * - pending_items (stated again, M1 columns kept): who added an item (the plugin or a person),
 *   the dedupe key that keeps a plugin item from being created twice, the room and night it is
 *   about, and who created and closed it (user ids only). No guest data: do not add such a column.
 */
final class Migration0010Screens implements Migration
{
    public function version(): int
    {
        return 10;
    }

    public function description(): string
    {
        return 'To-do items of the plugin and of people: source, dedupe key, room and night, created and closed by.';
    }

    public function tables(): array
    {
        return [
            new Table('pending_items', [
                Column::text('property_code', 32)->default(''),
                Column::text('kind', 32),
                Column::text('title', 190),
                Column::longText('details'),
                Column::text('status', 16)->default('open'),
                Column::date('due_on')->nullable(),
                Column::dateTime('created_at'),
                Column::dateTime('updated_at'),
                Column::dateTime('closed_at')->nullable(),
                Column::text('source', 16)->default('person'),
                Column::text('dedupe_key', 190)->default(''),
                Column::text('room_code', 32)->default(''),
                Column::date('night')->nullable(),
                Column::bigInt('created_by')->default(0),
                Column::bigInt('closed_by')->nullable(),
            ], [
                Index::plain('status', 'status'),
                Index::plain('due', 'status', 'due_on'),
                Index::plain('dedupe', 'dedupe_key'),
            ]),
        ];
    }
}
