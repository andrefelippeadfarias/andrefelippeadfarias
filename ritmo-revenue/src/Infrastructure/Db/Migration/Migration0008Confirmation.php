<?php

declare(strict_types=1);

namespace Ritmo\Infrastructure\Db\Migration;

use Ritmo\Infrastructure\Db\Schema\Column;
use Ritmo\Infrastructure\Db\Schema\Index;
use Ritmo\Infrastructure\Db\Schema\Table;

/**
 * Confirmation, record and approval (milestone M3).
 *
 * - change_log: group, undone change, confirmation level, order and the amounts of percentages;
 * - step_ledger: when a step reached the channel (push and PMS price) and when it was undone;
 * - plan_approvals: plans waiting for a person, with their expiry and the decision;
 * - night_observations: what each read found per room night, for third-party changes;
 * - detected_freezes: nights frozen because someone else changed them;
 * - wave_holds: the hold of a cancellation wave on a property.
 *
 * No column can hold guest data: bookings appear only by their PMS id.
 */
final class Migration0008Confirmation implements Migration
{
    public function version(): int
    {
        return 8;
    }

    public function description(): string
    {
        return 'Confirmation and record: change log undo fields, delivered and undone steps, plan approvals, night observations, detected freezes and wave holds.';
    }

    public function tables(): array
    {
        return [
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
                Column::text('group_key', 64)->default(''),
                Column::bigInt('undo_of')->nullable(),
                Column::text('confirm_level', 16)->default(''),
                Column::text('order_id', 64)->default(''),
                Column::float('old_amount')->nullable(),
                Column::float('new_amount')->nullable(),
            ], [
                Index::plain('property', 'property_code', 'created_at'),
                Index::plain('night', 'room_code', 'night'),
                Index::plain('run', 'run_id'),
            ]),
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
                Column::dateTime('reached_channel_at')->nullable(),
                Column::dateTime('undone_at')->nullable(),
                Column::text('write_ref', 64)->default(''),
            ], [
                Index::plain('night', 'room_code', 'night', 'recorded_at'),
                Index::plain('pending', 'property_code', 'reached_channel'),
            ]),
            new Table('plan_approvals', [
                Column::bigInt('run_id')->nullable(),
                Column::text('property_code', 32),
                Column::text('kind', 16),
                Column::text('status', 24),
                Column::text('plan_hash', 64),
                Column::date('plan_day'),
                Column::int('ruleset_version')->nullable(),
                Column::dateTime('calendar_read_at'),
                Column::dateTime('expires_at'),
                Column::text('expiry_reason', 64)->default(''),
                Column::longText('entries'),
                Column::longText('approved_keys')->nullable(),
                Column::longText('rejected_keys')->nullable(),
                Column::bigInt('decided_by')->nullable(),
                Column::dateTime('decided_at')->nullable(),
                Column::text('capability', 32)->default(''),
                Column::longText('note')->nullable(),
                Column::bigInt('write_run_id')->nullable(),
                Column::dateTime('created_at'),
            ], [
                Index::plain('status', 'property_code', 'status'),
                Index::plain('run', 'run_id'),
            ]),
            new Table('night_observations', [
                Column::text('property_code', 32),
                Column::text('provider', 16),
                Column::text('room_code', 32),
                Column::date('night'),
                Column::text('fingerprint', 64),
                Column::longText('fields'),
                Column::text('origin', 16)->default('observed'),
                Column::bigInt('run_id')->nullable(),
                Column::dateTime('observed_at'),
            ], [
                Index::plain('night', 'provider', 'room_code', 'night', 'observed_at'),
                Index::plain('property', 'property_code', 'observed_at'),
            ]),
            new Table('detected_freezes', [
                Column::text('property_code', 32),
                Column::text('room_code', 32),
                Column::longText('nights'),
                Column::longText('fields'),
                Column::dateTime('detected_at'),
                Column::dateTime('frozen_until')->nullable(),
                Column::dateTime('released_at')->nullable(),
                Column::bigInt('released_by')->nullable(),
                Column::bigInt('run_id')->nullable(),
            ], [Index::plain('active', 'property_code', 'released_at')]),
            new Table('wave_holds', [
                Column::text('property_code', 32),
                Column::dateTime('since'),
                Column::dateTime('last_cancellation_at'),
                Column::longText('nights'),
                Column::longText('booking_refs'),
                Column::dateTime('released_at')->nullable(),
                Column::bigInt('released_by')->nullable(),
                Column::dateTime('updated_at'),
            ], [Index::plain('active', 'property_code', 'released_at')]),
        ];
    }
}
