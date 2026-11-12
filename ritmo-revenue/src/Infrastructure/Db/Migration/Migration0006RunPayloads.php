<?php

declare(strict_types=1);

namespace Ritmo\Infrastructure\Db\Migration;

use Ritmo\Infrastructure\Db\Schema\Column;
use Ritmo\Infrastructure\Db\Schema\Index;
use Ritmo\Infrastructure\Db\Schema\Table;

/**
 * What a run would send, kept with the run: the payload of its validated plan and the slices it
 * is cut into, in writing order. Data only: nothing reads it to send it in this milestone.
 */
final class Migration0006RunPayloads implements Migration
{
    public function version(): int
    {
        return 6;
    }

    public function description(): string
    {
        return 'Payload and slices of the validated plan of a run.';
    }

    public function tables(): array
    {
        return [
            new Table('run_payloads', [
                Column::bigInt('run_id'),
                Column::text('property_code', 32),
                Column::text('kind', 16),
                Column::int('position'),
                Column::longText('body'),
                Column::dateTime('created_at'),
            ], [Index::plain('run', 'run_id', 'kind', 'position')]),
        ];
    }
}
