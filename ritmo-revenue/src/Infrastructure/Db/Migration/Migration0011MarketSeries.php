<?php

declare(strict_types=1);

namespace Ritmo\Infrastructure\Db\Migration;

use Ritmo\Infrastructure\Db\Schema\Column;
use Ritmo\Infrastructure\Db\Schema\Index;
use Ritmo\Infrastructure\Db\Schema\Table;

/**
 * Market data of milestone M4.
 *
 * - competitors (stated again, M1 columns kept): a code unique per property (what a price file
 *   names), the comparable factor and its source, a rating shown only, and the flag of the
 *   property's own listing.
 * - price_samples (stated again, M1 columns kept): one row per stay (check-in and nights), the
 *   room basis, the stay total as given, the currency, the import it came from and the
 *   deduplication key (unique per property; null on M1 rows, which read as one-night comparable
 *   stays of their night).
 * - market_imports: one row per imported price file: who, the file's name and hash, counts and
 *   at most 200 problems ({line, column, code}); never the file nor a cell's content.
 * - market_series: the value of each series on each night (a cache rebuilt from the samples).
 * - ladder_reference: the reference sent price of each room and night for a sales ladder strategy,
 *   taken once per strategy version; never edited.
 */
final class Migration0011MarketSeries implements Migration
{
    public function version(): int
    {
        return 11;
    }

    public function description(): string
    {
        return 'Market: competitor codes and factors, stay samples, price file imports, series per night and ladder reference prices.';
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
                Column::text('code', 64)->nullable(),
                Column::float('comparable_factor')->nullable(),
                Column::text('factor_source', 16)->default(''),
                Column::float('rating')->nullable(),
                Column::bool('is_own')->default(0),
            ], [Index::plain('property', 'property_code'), Index::unique('code', 'property_code', 'code')]),

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
                Column::date('stay_from')->nullable(),
                Column::int('stay_nights')->default(1),
                Column::text('room_basis', 16)->default('comparable'),
                Column::float('total')->nullable(),
                Column::text('currency', 3)->default(''),
                Column::bigInt('import_id')->nullable(),
                Column::text('dedup_key', 64)->nullable(),
            ], [
                Index::plain('night', 'property_code', 'night'),
                Index::plain('competitor', 'competitor_id', 'night'),
                Index::unique('dedup', 'property_code', 'dedup_key'),
            ]),

            new Table('market_imports', [
                Column::text('property_code', 32),
                Column::bigInt('user_id')->default(0),
                Column::text('file_name', 120)->default(''),
                Column::text('file_sha256', 64),
                Column::int('bytes')->default(0),
                Column::int('rows_total')->default(0),
                Column::int('rows_imported')->default(0),
                Column::int('rows_replaced')->default(0),
                Column::int('rows_invalid')->default(0),
                Column::int('rows_warned')->default(0),
                Column::longText('problems')->nullable(),
                Column::dateTime('created_at'),
            ], [Index::plain('file', 'property_code', 'file_sha256')]),

            new Table('market_series', [
                Column::text('property_code', 32),
                Column::date('night'),
                Column::text('series', 32),
                Column::longText('series_values'),
                Column::int('competitors')->default(0),
                Column::int('stays')->default(0),
                Column::text('origin', 16),
                Column::int('distance_days')->nullable(),
                Column::date('oldest_capture')->nullable(),
                Column::date('newest_capture')->nullable(),
                Column::text('inputs_hash', 64)->default(''),
                Column::dateTime('built_at'),
            ], [Index::unique('night', 'property_code', 'night', 'series')]),

            new Table('ladder_reference', [
                Column::text('property_code', 32),
                Column::text('strategy_id', 64),
                Column::text('room_code', 32),
                Column::date('night'),
                Column::float('sent_price'),
                Column::int('version')->default(1),
                Column::dateTime('taken_at'),
            ], [Index::unique('entry', 'property_code', 'strategy_id', 'version', 'room_code', 'night')]),
        ];
    }
}
