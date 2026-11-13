<?php

declare(strict_types=1);

namespace Ritmo\Infrastructure\Db\Migration;

use Ritmo\Infrastructure\Db\Schema\Column;
use Ritmo\Infrastructure\Db\Schema\Index;
use Ritmo\Infrastructure\Db\Schema\Table;

/**
 * Reports of milestone M4.
 *
 * - report_snapshots: a saved review (12-month review) per property and day: totals only, never a
 *   booking; one per property, kind and day.
 * - revenue_targets: what the owner typed per property and month (last year's market, occupancy
 *   cap, adjustments, minimum and realistic targets, the market's sold occupancy); every save is
 *   also written to the change log.
 * - holiday_targets: per property and holiday period, the target occupancy and daily rate of each
 *   scenario and what is sold.
 */
final class Migration0012Reports implements Migration
{
    public function version(): int
    {
        return 12;
    }

    public function description(): string
    {
        return 'Reports: saved reviews, monthly revenue targets and holiday targets.';
    }

    public function tables(): array
    {
        $money = static fn (string $name): Column => Column::float($name)->nullable();

        return [
            new Table('report_snapshots', [
                Column::text('property_code', 32),
                Column::text('kind', 32),
                Column::date('taken_on'),
                Column::longText('data'),
                Column::bigInt('created_by')->default(0),
                Column::dateTime('created_at'),
            ], [Index::unique('snapshot', 'property_code', 'kind', 'taken_on')]),
            new Table('revenue_targets', [
                Column::text('property_code', 32),
                Column::text('month', 7),
                $money('minimum'),
                $money('realistic'),
                Column::float('market_occupancy_ly')->nullable(),
                $money('market_adr_ly'),
                Column::float('occupancy_cap')->default(1),
                Column::float('adjust_floor')->default(0),
                Column::float('adjust_base')->default(0),
                Column::float('adjust_goal')->default(0),
                Column::float('market_sold_percent')->nullable(),
                Column::float('market_last_year_percent')->nullable(),
                Column::bigInt('updated_by')->default(0),
                Column::dateTime('updated_at'),
            ], [Index::unique('month', 'property_code', 'month')]),
            new Table('holiday_targets', [
                Column::text('property_code', 32),
                Column::text('period', 64),
                Column::int('room_nights')->default(0),
                Column::int('sold_nights')->default(0),
                Column::float('sold_revenue')->default(0),
                Column::float('occupancy_floor'),
                Column::float('occupancy_base'),
                Column::float('occupancy_goal'),
                Column::float('adr_floor'),
                Column::float('adr_base'),
                Column::float('adr_goal'),
                Column::bigInt('updated_by')->default(0),
                Column::dateTime('updated_at'),
            ], [Index::unique('period', 'property_code', 'period')]),
        ];
    }
}
