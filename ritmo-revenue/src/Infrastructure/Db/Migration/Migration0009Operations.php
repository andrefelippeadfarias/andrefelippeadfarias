<?php

declare(strict_types=1);

namespace Ritmo\Infrastructure\Db\Migration;

use Ritmo\Infrastructure\Db\Schema\Column;
use Ritmo\Infrastructure\Db\Schema\Index;
use Ritmo\Infrastructure\Db\Schema\Table;

/**
 * Operations (milestone M3: scheduler, light run, alerts).
 *
 * - scheduled_runs: one row per slot occurrence of a property (unique per property, local
 *   date-time and kind), with what became of it: status, promoted, run, start, end and delay.
 * - operation_state: per property, the pause and the cursors of the scheduler (slots considered
 *   until) and of the movement read (the instant before the last successful read).
 * - booking_movements: per property, every processed version of a booking (fingerprint), so the
 *   overlap of two reads never counts a sale twice. No guest data: do not add such a column.
 * - alerts (stated again, M1 columns kept): the lifecycle columns (evidence, acknowledgement and
 *   resolution by whom, e-mail and digest instants, occurrences) and the dedupe lookup by status.
 */
final class Migration0009Operations implements Migration
{
    public function version(): int
    {
        return 9;
    }

    public function description(): string
    {
        return 'Scheduler slots and operation state, processed booking versions, and the lifecycle of alerts.';
    }

    public function tables(): array
    {
        return [
            new Table('scheduled_runs', [
                Column::text('property_code', 32),
                Column::text('slot_kind', 16),
                Column::text('slot_local', 16),
                Column::dateTime('slot_at'),
                Column::text('status', 24)->default('due'),
                Column::bool('promoted')->default(0),
                Column::bigInt('run_id')->nullable(),
                Column::dateTime('started_at')->nullable(),
                Column::dateTime('finished_at')->nullable(),
                Column::int('delay_seconds')->nullable(),
                Column::text('note', 190)->default(''),
                Column::dateTime('created_at'),
            ], [
                Index::unique('slot', 'property_code', 'slot_local', 'slot_kind'),
                Index::plain('due', 'status', 'slot_at'),
                Index::plain('run', 'run_id'),
            ]),

            new Table('operation_state', [
                Column::text('property_code', 32),
                Column::bool('paused')->default(0),
                Column::dateTime('paused_until')->nullable(),
                Column::bigInt('paused_by')->nullable(),
                Column::text('pause_reason', 190)->default(''),
                Column::dateTime('movement_cursor')->nullable(),
                Column::dateTime('slots_considered_until')->nullable(),
                Column::dateTime('updated_at'),
            ], [Index::unique('property', 'property_code')]),

            new Table('booking_movements', [
                Column::text('property_code', 32),
                Column::text('pms', 32),
                Column::text('pms_ref', 64),
                Column::text('fingerprint', 64),
                Column::text('kind', 16),
                Column::text('room_code', 32)->nullable(),
                Column::date('arrival'),
                Column::date('departure'),
                Column::text('status', 16),
                Column::int('units')->default(1),
                Column::float('total')->nullable(),
                Column::dateTime('modified_at')->nullable(),
                Column::dateTime('cancelled_at')->nullable(),
                Column::bigInt('run_id')->nullable(),
                Column::dateTime('detected_at'),
            ], [
                Index::unique('version', 'property_code', 'pms', 'pms_ref', 'fingerprint'),
                Index::plain('booking', 'property_code', 'pms', 'pms_ref', 'detected_at'),
                Index::plain('departure', 'departure'),
            ]),

            new Table('alerts', [
                Column::text('property_code', 32)->default(''),
                Column::text('room_code', 32)->default(''),
                Column::date('night')->nullable(),
                Column::text('code', 64),
                Column::text('severity', 16),
                Column::longText('message'),
                Column::longText('context'),
                Column::text('status', 16)->default('open'),
                Column::text('dedupe_key', 190)->default(''),
                Column::bigInt('run_id')->nullable(),
                Column::dateTime('created_at'),
                Column::dateTime('updated_at'),
                Column::dateTime('resolved_at')->nullable(),
                Column::dateTime('evidence_at')->nullable(),
                Column::dateTime('acknowledged_at')->nullable(),
                Column::bigInt('acknowledged_by')->nullable(),
                Column::bigInt('resolved_by')->nullable(),
                Column::dateTime('notified_at')->nullable(),
                Column::dateTime('digested_at')->nullable(),
                Column::int('occurrences')->default(1),
            ], [
                Index::plain('status', 'status', 'severity'),
                Index::plain('dedupe', 'dedupe_key'),
                Index::plain('property', 'property_code', 'created_at'),
                Index::plain('dedupe_status', 'dedupe_key', 'status'),
                Index::plain('notified', 'property_code', 'notified_at'),
            ]),
        ];
    }
}
