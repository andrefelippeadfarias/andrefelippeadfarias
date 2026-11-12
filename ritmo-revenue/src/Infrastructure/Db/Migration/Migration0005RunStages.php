<?php

declare(strict_types=1);

namespace Ritmo\Infrastructure\Db\Migration;

use Ritmo\Infrastructure\Db\Schema\Column;
use Ritmo\Infrastructure\Db\Schema\Index;
use Ritmo\Infrastructure\Db\Schema\Table;

/**
 * Runs made of resumable stages: the run remembers the property day it is for, the last stage
 * it completed and the data the next stage starts from.
 */
final class Migration0005RunStages implements Migration
{
    public function version(): int
    {
        return 5;
    }

    public function description(): string
    {
        return 'Resumable runs: day of the run, last completed stage and the state carried between stages.';
    }

    public function tables(): array
    {
        return [
            new Table('runs', [
                Column::text('property_code', 32),
                Column::text('kind', 16),
                Column::text('mode', 16),
                Column::text('status', 16),
                Column::int('ruleset_version')->nullable(),
                Column::dateTime('started_at'),
                Column::dateTime('finished_at')->nullable(),
                Column::longText('summary'),
                Column::longText('error')->nullable(),
                Column::date('run_day')->nullable(),
                Column::text('stage', 16)->default(''),
                Column::longText('state')->nullable(),
            ], [
                Index::plain('property', 'property_code', 'started_at'),
                Index::plain('status', 'status'),
                Index::plain('open', 'property_code', 'kind', 'status'),
            ]),
        ];
    }
}
