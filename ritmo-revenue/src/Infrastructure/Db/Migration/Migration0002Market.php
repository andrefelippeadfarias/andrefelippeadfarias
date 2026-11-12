<?php

declare(strict_types=1);

namespace Ritmo\Infrastructure\Db\Migration;

use Ritmo\Infrastructure\Db\Schema\Column;
use Ritmo\Infrastructure\Db\Schema\Index;
use Ritmo\Infrastructure\Db\Schema\Table;

/**
 * Market data: the customer's competitors, the price samples collected for them and the
 * market anchor computed per property and night.
 */
final class Migration0002Market implements Migration
{
    public function version(): int
    {
        return 2;
    }

    public function description(): string
    {
        return 'Market tables: competitors, price samples and market anchors.';
    }

    public function tables(): array
    {
        return [
            new Table('competitors', [
                Column::text('property_code', 32),
                Column::text('name', 190),
                Column::text('external_ref', 190)->default(''),
                Column::text('room_label', 190)->default(''),
                Column::longText('tags'),
                Column::bool('active')->default(1),
                Column::dateTime('created_at'),
                Column::dateTime('updated_at'),
            ], [Index::plain('property', 'property_code')]),

            new Table('price_samples', [
                Column::text('property_code', 32),
                Column::bigInt('competitor_id')->default(0),
                Column::date('night'),
                Column::float('price'),
                Column::text('plan', 16)->default('public'),
                Column::bool('available')->default(1),
                Column::int('min_stay')->nullable(),
                Column::text('source', 32)->default('manual'),
                Column::dateTime('captured_at'),
            ], [Index::plain('night', 'property_code', 'night'), Index::plain('competitor', 'competitor_id', 'night')]),

            new Table('market_anchor', [
                Column::text('property_code', 32),
                Column::date('night'),
                Column::float('p25'),
                Column::float('p50'),
                Column::float('p75'),
                Column::float('p90'),
                Column::float('max_price'),
                Column::int('sample_size'),
                Column::date('captured_on')->nullable(),
                Column::text('origin', 16),
                Column::dateTime('updated_at'),
            ], [Index::unique('night', 'property_code', 'night')]),
        ];
    }
}
