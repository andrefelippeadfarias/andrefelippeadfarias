<?php

declare(strict_types=1);

namespace Ritmo\Infrastructure\Db\Migration;

use Ritmo\Infrastructure\Db\Schema\Column;
use Ritmo\Infrastructure\Db\Schema\Index;
use Ritmo\Infrastructure\Db\Schema\Table;

/**
 * DRAFT of the write path's migration (milestone M3; the integration gives it its number and
 * adds it to Migrations::all()).
 *
 * - write_ops, the write-ahead journal: one row per step of a write run, inserted as "planned"
 *   before the first call and marked "sending" before its own call, with the nights, the state
 *   read before (before_state), the state the step leaves (intended_state), what the read-back
 *   found (observed_state), the hashes of the token, the plan and the body, and the run that
 *   later classified it (resolved_run_id).
 * - api_quota_log: the outcome of a recalculation attempt (pending before the call, then ok,
 *   failure or rate_limited), its response id and its error line; "succeeded" stays for the
 *   readers of M1.
 */
final class DraftWrite implements Migration
{
    public function version(): int
    {
        return 7;
    }

    public function description(): string
    {
        return 'Write-ahead journal of the write path and outcomes of recalculation attempts.';
    }

    public function tables(): array
    {
        return [
            new Table('write_ops', [
                Column::bigInt('run_id')->nullable(),
                Column::text('property_code', 32),
                Column::text('provider', 32),
                Column::text('operation', 32),
                Column::text('target_ref', 64)->default(''),
                Column::text('group_key', 64)->default(''),
                Column::int('slice_number')->default(0),
                Column::text('label', 32)->default(''),
                Column::longText('dates')->nullable(),
                Column::longText('payload'),
                Column::longText('before_state')->nullable(),
                Column::longText('intended_state')->nullable(),
                Column::longText('observed_state')->nullable(),
                Column::text('status', 16)->default('planned'),
                Column::int('attempts')->default(0),
                Column::int('http_status')->nullable(),
                Column::longText('error')->nullable(),
                Column::text('token_id', 64)->default(''),
                Column::text('plan_hash', 64)->default(''),
                Column::text('body_hash', 64)->default(''),
                Column::bigInt('resolved_run_id')->nullable(),
                Column::dateTime('created_at'),
                Column::dateTime('started_at')->nullable(),
                Column::dateTime('finished_at')->nullable(),
            ], [
                Index::plain('run', 'run_id'),
                Index::plain('status', 'status'),
                Index::plain('open', 'property_code', 'resolved_run_id', 'status'),
                Index::plain('body', 'property_code', 'body_hash'),
            ]),

            new Table('api_quota_log', [
                Column::text('provider', 32),
                Column::text('action', 64),
                Column::text('scope', 64)->default(''),
                Column::bool('succeeded'),
                Column::text('outcome', 16)->default('ok'),
                Column::text('response_id', 64)->default(''),
                Column::longText('error')->nullable(),
                Column::dateTime('called_at'),
            ], [
                Index::plain('calls', 'provider', 'action', 'called_at'),
                Index::plain('scope', 'provider', 'action', 'scope', 'called_at'),
            ]),
        ];
    }
}
