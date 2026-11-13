<?php

declare(strict_types=1);

namespace Ritmo\Domain\Market\Series;

use Ritmo\Domain\Config\Field;
use Ritmo\Domain\Config\FieldType;

/**
 * The field definitions of the "market" section of the rule set: the series the ruler builds,
 * the percentiles it stores, when a stay or a night has enough competitors, how long a price
 * counts, the CSV limits and the labels of the market bands. Neutral, conservative defaults.
 */
final class MarketFields
{
    public const ROOT = 'market';

    /** The percentiles of the M1 anchor series ("market"). */
    public const ANCHOR_PERCENTILES = [25, 50, 75, 90, 100];

    private function __construct()
    {
    }

    /**
     * @return list<Field> parents before their children
     */
    public static function all(): array
    {
        $m = self::ROOT;

        return [
            new Field($m, FieldType::Group, 'Market', 'Competitor prices you import or type become price series the sales ladder and the pace read. Changing this section never changes a price: it applies from the next run.'),
            new Field("$m.series", FieldType::ListOf, 'Price series', 'Each series measures one kind of room among a group of competitors.', self::defaultSeries(), min: 0, max: 12),
            new Field("$m.series.*", FieldType::Group, 'Series', required: true),
            new Field("$m.series.*.name", FieldType::Text, 'Name', 'Short name the sales ladder rules cite.', required: true, pattern: SeriesDefinition::NAME_PATTERN),
            new Field("$m.series.*.basis", FieldType::Choice, 'Room measured', 'The cheapest room of each competitor, or a room comparable to yours.', RoomBasis::Cheapest->value, enum: [RoomBasis::Cheapest->value, RoomBasis::Comparable->value]),
            new Field("$m.series.*.tags", FieldType::ListOf, 'Only competitors tagged', 'Empty: every competitor.', [], max: 10, unique: true),
            new Field("$m.series.*.tags.*", FieldType::Text, 'Tag', required: true, pattern: CompetitorProfile::TAG_PATTERN),
            new Field("$m.percentiles", FieldType::ListOf, 'Percentiles kept', 'Points of each series the ruler stores, from the cheapest (low numbers) to the dearest (high numbers).', [10, 25, 40, 50, 65, 75, 90], min: 1, max: 12, unique: true),
            new Field("$m.percentiles.*", FieldType::Integer, 'Percentile', required: true, min: 1, max: 99),
            new Field("$m.min_competitors_per_stay", FieldType::Integer, 'Competitors needed for a stay', 'A searched stay counts for a series only with at least this many competitor prices.', 10, min: 1, max: 200),
            new Field("$m.night_min_competitors", FieldType::Integer, 'Competitors needed for a night on its own', 'A stay starting on a night sets that night alone only with at least this many prices; otherwise the stays around it are used.', 20, min: 1, max: 500),
            new Field("$m.neighbour_days", FieldType::Integer, 'Nearest stays up to', 'A night without a stay of its own uses the nearest stays of the same kind up to this many days away.', 3, min: 0, max: 14, unit: Field::UNIT_DAYS),
            new Field("$m.max_sample_age_days", FieldType::Integer, 'A price counts for', 'Prices captured longer ago than this no longer count.', 30, min: 1, max: 365, unit: Field::UNIT_DAYS),
            new Field("$m.retention_days", FieldType::Integer, 'Keep prices for', 'Prices older than this are deleted.', 400, min: 30, max: 1095, unit: Field::UNIT_DAYS),
            new Field("$m.comparable", FieldType::Group, 'Comparable rooms'),
            new Field("$m.comparable.default_factor", FieldType::Number, 'Usual price of a comparable room', 'How many times the cheapest room a comparable room costs, for a competitor without a factor of its own.', 1.0, min: 0.5, max: 5),
            new Field("$m.comparable.estimated_factor_cap", FieldType::Number, 'Highest estimated factor', 'An estimated factor above this is lowered to it.', 2.0, min: 1, max: 10),
            new Field("$m.small_sample", FieldType::Group, 'Small sample warning'),
            new Field("$m.small_sample.stays", FieldType::Integer, 'Fewer stays than', 'A kind of night measured on fewer stays is marked as a small sample on the market screen.', 4, min: 1, max: 50),
            new Field("$m.small_sample.competitors", FieldType::Integer, 'Fewer competitors than', 'A kind of night whose stays have fewer competitors (median) is marked as a small sample.', 40, min: 1, max: 500),
            new Field("$m.stale_after_days", FieldType::Integer, 'Remind me after', 'When the newest price is older than this, the plugin reminds you to import fresh prices.', 7, min: 1, max: 60, unit: Field::UNIT_DAYS),
            new Field("$m.reminder_horizon_days", FieldType::Integer, 'Nights the reminder looks at', 'The reminder checks the nights from today to this many days ahead.', 60, min: 7, max: 366, unit: Field::UNIT_DAYS),
            new Field("$m.csv", FieldType::Group, 'Price file'),
            new Field("$m.csv.max_bytes", FieldType::Integer, 'Largest file', 'In bytes; never above the upload limit of the site.', 2097152, min: 102400, max: 10485760),
            new Field("$m.csv.max_rows", FieldType::Integer, 'Most rows in a file', '', 20000, min: 100, max: 50000),
            new Field("$m.csv.max_stay_nights", FieldType::Integer, 'Longest stay in a row', '', 7, min: 1, max: 30, unit: Field::UNIT_NIGHTS),
            new Field("$m.bands", FieldType::ListOf, 'Market bands', 'Names of points of the market shown on the market screen.', self::defaultBands(), max: 10),
            new Field("$m.bands.*", FieldType::Group, 'Band', required: true),
            new Field("$m.bands.*.label", FieldType::Text, 'Name', required: true, min: 1, max: 40),
            new Field("$m.bands.*.percentile", FieldType::Integer, 'Percentile', required: true, min: 1, max: 99),
        ];
    }

    /**
     * @return list<array{name: string, basis: string, tags: list<string>}>
     */
    public static function defaultSeries(): array
    {
        return [
            ['name' => 'cheapest', 'basis' => RoomBasis::Cheapest->value, 'tags' => []],
            ['name' => 'comparable', 'basis' => RoomBasis::Comparable->value, 'tags' => []],
            ['name' => 'peers_cheapest', 'basis' => RoomBasis::Cheapest->value, 'tags' => ['peer']],
            ['name' => 'peers_comparable', 'basis' => RoomBasis::Comparable->value, 'tags' => ['peer']],
        ];
    }

    /**
     * @return list<array{label: string, percentile: int}>
     */
    public static function defaultBands(): array
    {
        return [
            ['label' => 'Fill', 'percentile' => 25],
            ['label' => 'Market', 'percentile' => 50],
            ['label' => 'Above market', 'percentile' => 65],
            ['label' => 'Premium', 'percentile' => 75],
            ['label' => 'Top', 'percentile' => 90],
        ];
    }
}
