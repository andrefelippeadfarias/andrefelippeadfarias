<?php

declare(strict_types=1);

namespace Ritmo\Infrastructure\Db\Migration;

use Ritmo\Infrastructure\Db\Schema\Column;
use Ritmo\Infrastructure\Db\Schema\Index;
use Ritmo\Infrastructure\Db\Schema\Table;

/**
 * What the customer configures: properties, room types, relations between rooms, periods,
 * rule set versions and measured price factors.
 */
final class Migration0001Configuration implements Migration
{
    public function version(): int
    {
        return 1;
    }

    public function description(): string
    {
        return 'Configuration tables: properties, room types, relations, periods, rule sets and price factors.';
    }

    public function tables(): array
    {
        return [
            new Table('properties', [
                Column::text('code', 32),
                Column::text('name', 190),
                Column::text('timezone', 64),
                Column::text('currency', 8),
                Column::text('mode', 16)->default('shadow'),
                Column::text('tier', 32)->default('standard'),
                Column::longText('occupancy_goals'),
                Column::dateTime('created_at'),
                Column::dateTime('updated_at'),
            ], [Index::unique('code', 'code')]),

            new Table('room_types', [
                Column::text('property_code', 32),
                Column::text('code', 32),
                Column::text('name', 190),
                Column::int('units')->default(1),
                Column::text('pms_ref', 64)->default(''),
                Column::text('pricing_ref', 64)->default(''),
                Column::float('base_price'),
                Column::float('min_price'),
                Column::float('max_price'),
                Column::float('reference_min'),
                Column::longText('tags'),
                Column::int('position')->default(0),
                Column::dateTime('created_at'),
                Column::dateTime('updated_at'),
            ], [Index::unique('code', 'code'), Index::plain('property', 'property_code', 'position')]),

            new Table('relations', [
                Column::text('property_code', 32),
                Column::text('kind', 32),
                Column::text('room_code', 32),
                Column::longText('params'),
                Column::int('position')->default(0),
                Column::bool('enabled')->default(1),
                Column::dateTime('created_at'),
                Column::dateTime('updated_at'),
            ], [Index::plain('property', 'property_code', 'position')]),

            new Table('periods', [
                Column::text('property_code', 32)->default(''),
                Column::text('kind', 16),
                Column::text('name', 190),
                Column::date('first_night'),
                Column::date('last_night'),
                Column::longText('params'),
                Column::dateTime('created_at'),
                Column::dateTime('updated_at'),
            ], [Index::plain('nights', 'first_night', 'last_night'), Index::plain('kind', 'kind')]),

            // One row per saved version; rows are never updated or deleted.
            new Table('rulesets', [
                Column::int('version'),
                Column::text('hash', 64),
                Column::longText('rules'),
                Column::text('note', 190)->default(''),
                Column::bigInt('created_by')->default(0),
                Column::dateTime('created_at'),
            ], [Index::unique('version', 'version'), Index::plain('hash', 'hash')]),

            new Table('price_factors', [
                Column::text('property_code', 32),
                Column::text('room_code', 32)->default(''),
                Column::text('plan', 16),
                Column::text('night_type', 16),
                Column::float('factor'),
                Column::int('sample_size')->default(0),
                Column::text('source', 32)->default(''),
                Column::dateTime('measured_at'),
            ], [Index::plain('lookup', 'property_code', 'plan', 'night_type')]),
        ];
    }
}
