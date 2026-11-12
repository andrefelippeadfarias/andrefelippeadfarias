<?php

declare(strict_types=1);

namespace Ritmo\Infrastructure\Db\Migration;

use Ritmo\Infrastructure\Db\Schema\Column;
use Ritmo\Infrastructure\Db\Schema\Index;
use Ritmo\Infrastructure\Db\Schema\Table;

/**
 * What the plugin reads and produces while it works: owner orders, bookings, inventory
 * snapshots, runs and their plan, write operations, the step ledger, the change log, alerts,
 * pending items and the quota log.
 */
final class Migration0003Operation implements Migration
{
    public function version(): int
    {
        return 3;
    }

    public function description(): string
    {
        return 'Operation tables: orders, bookings, snapshots, runs, plan items, write operations, step ledger, change log, alerts, pending items and quota log.';
    }

    public function tables(): array
    {
        return [
            new Table('orders', [
                Column::text('property_code', 32),
                Column::text('room_code', 32)->default(''),
                Column::text('kind', 32),
                Column::date('first_night')->nullable(),
                Column::date('last_night')->nullable(),
                Column::longText('params'),
                Column::text('status', 16)->default('active'),
                Column::longText('note')->nullable(),
                Column::bigInt('created_by')->default(0),
                Column::dateTime('created_at'),
                Column::dateTime('updated_at'),
                Column::dateTime('expires_at')->nullable(),
            ], [Index::plain('status', 'property_code', 'status')]),

            // Bookings carry what occupancy and pace need and nothing that identifies a guest:
            // no name, no contact, no address, no free text. Do not add such a column.
            new Table('bookings', [
                Column::text('pms', 32),
                Column::text('pms_ref', 64),
                Column::text('room_code', 32)->nullable(),
                Column::text('room_pms_ref', 64)->default(''),
                Column::date('arrival'),
                Column::date('departure'),
                Column::text('status', 16),
                Column::text('channel', 64)->default(''),
                Column::text('origin', 64)->default(''),
                Column::float('total')->nullable(),
                Column::int('units')->default(1),
                Column::dateTime('booked_at')->nullable(),
                Column::dateTime('modified_at')->nullable(),
                Column::dateTime('cancelled_at')->nullable(),
                Column::dateTime('synced_at'),
            ], [
                Index::unique('pms_ref', 'pms', 'pms_ref'),
                Index::plain('stay', 'arrival', 'departure'),
                Index::plain('room', 'room_code', 'arrival'),
                Index::plain('modified', 'modified_at'),
            ]),

            new Table('snapshots', [
                Column::text('room_code', 32),
                Column::date('night'),
                Column::dateTime('captured_at'),
                Column::bigInt('run_id')->nullable(),
                Column::int('available'),
                Column::int('min_stay')->nullable(),
                Column::int('max_stay')->nullable(),
                Column::text('restriction', 24)->default('none'),
                Column::float('pms_price')->nullable(),
            ], [Index::unique('capture', 'room_code', 'night', 'captured_at'), Index::plain('captured', 'captured_at')]),

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
            ], [Index::plain('property', 'property_code', 'started_at'), Index::plain('status', 'status')]),

            new Table('plan_items', [
                Column::bigInt('run_id'),
                Column::text('property_code', 32),
                Column::text('room_code', 32),
                Column::date('night'),
                Column::text('field', 32),
                Column::text('plan', 16)->default('sent'),
                Column::float('current_value')->nullable(),
                Column::float('proposed_value')->nullable(),
                Column::text('reason', 64)->default(''),
                Column::longText('details'),
                Column::text('status', 16)->default('proposed'),
                Column::dateTime('created_at'),
            ], [Index::plain('run', 'run_id'), Index::plain('night', 'room_code', 'night')]),

            new Table('write_ops', [
                Column::bigInt('run_id')->nullable(),
                Column::text('property_code', 32),
                Column::text('provider', 32),
                Column::text('operation', 32),
                Column::text('target_ref', 64)->default(''),
                Column::text('group_key', 64)->default(''),
                Column::longText('payload'),
                Column::text('status', 16)->default('pending'),
                Column::int('attempts')->default(0),
                Column::int('http_status')->nullable(),
                Column::longText('error')->nullable(),
                Column::dateTime('created_at'),
                Column::dateTime('started_at')->nullable(),
                Column::dateTime('finished_at')->nullable(),
            ], [Index::plain('run', 'run_id'), Index::plain('status', 'status')]),

            new Table('step_ledger', [
                Column::text('property_code', 32),
                Column::text('room_code', 32),
                Column::date('night'),
                Column::text('direction', 8),
                Column::float('from_value'),
                Column::float('to_value'),
                Column::text('pace_status', 16)->default(''),
                Column::int('units_sold')->nullable(),
                Column::int('units_total')->nullable(),
                Column::text('count_basis', 16)->default('room'),
                Column::bool('reached_channel')->default(0),
                Column::bigInt('run_id')->nullable(),
                Column::dateTime('recorded_at'),
            ], [Index::plain('night', 'room_code', 'night', 'recorded_at')]),

            new Table('change_log', [
                Column::bigInt('run_id')->nullable(),
                Column::text('property_code', 32),
                Column::text('room_code', 32)->default(''),
                Column::date('night')->nullable(),
                Column::text('provider', 32)->default(''),
                Column::text('field', 32),
                Column::longText('old_value')->nullable(),
                Column::longText('new_value')->nullable(),
                Column::longText('reason')->nullable(),
                Column::longText('undo_plan')->nullable(),
                Column::text('actor', 64)->default('system'),
                Column::dateTime('created_at'),
                Column::dateTime('undone_at')->nullable(),
            ], [Index::plain('property', 'property_code', 'created_at'), Index::plain('night', 'room_code', 'night')]),

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
            ], [
                Index::plain('status', 'status', 'severity'),
                Index::plain('dedupe', 'dedupe_key'),
                Index::plain('property', 'property_code', 'created_at'),
            ]),

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
            ], [Index::plain('status', 'status')]),

            new Table('api_quota_log', [
                Column::text('provider', 32),
                Column::text('action', 64),
                Column::text('scope', 64)->default(''),
                Column::bool('succeeded'),
                Column::dateTime('called_at'),
            ], [
                Index::plain('calls', 'provider', 'action', 'called_at'),
                Index::plain('scope', 'provider', 'action', 'scope', 'called_at'),
            ]),
        ];
    }
}
