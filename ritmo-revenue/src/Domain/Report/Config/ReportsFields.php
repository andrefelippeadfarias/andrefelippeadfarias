<?php

declare(strict_types=1);

namespace Ritmo\Domain\Report\Config;

use Ritmo\Domain\Config\Field;
use Ritmo\Domain\Config\FieldType;
use Ritmo\Domain\Model\Identifier;

/**
 * The definitions of the "reports" section of the rule set (spec 06, 2.9): the occupancy map and
 * groups, the 12-month review, the weekly panel's start and the forecast scenarios. Reports never
 * change a price; neutral defaults: no group, growth and index 1, no season minimum.
 */
final class ReportsFields
{
    public const ROOT = 'reports';

    private const MAX_PROPERTIES = 50;

    private function __construct()
    {
    }

    /**
     * @return list<Field>
     */
    public static function all(): array
    {
        return [
            new Field('reports', FieldType::Group, 'Reports', 'What the reports show; they never change a price.'),
            ...self::occupancy(),
            ...self::review(),
            new Field('reports.weekly', FieldType::Group, 'Weekly panel'),
            new Field('reports.weekly.start_date', FieldType::Date, 'First week', 'The weekly panel starts at the Monday of this date; empty for the last 26 weeks.', null, nullable: true),
            ...self::forecast(),
        ];
    }

    /**
     * @return list<Field>
     */
    private static function occupancy(): array
    {
        return [
            new Field('reports.occupancy', FieldType::Group, 'Occupancy'),
            new Field('reports.occupancy.map_nights', FieldType::Integer, 'Nights on the map', 'How many nights the occupancy map shows, starting today.', 14, min: 1, max: 60, unit: Field::UNIT_NIGHTS),
            new Field('reports.occupancy.groups', FieldType::ListOf, 'Room groups', 'Extra rows of the occupancy table: the rooms carrying any of the tags.', [], max: 20),
            new Field('reports.occupancy.groups.*', FieldType::Group, 'Room group'),
            new Field('reports.occupancy.groups.*.label', FieldType::Text, 'Name', required: true, min: 1, max: 60),
            new Field('reports.occupancy.groups.*.tags', FieldType::ListOf, 'Room tags', required: true, min: 1, max: 20, unique: true),
            new Field('reports.occupancy.groups.*.tags.*', FieldType::Text, 'Tag', pattern: Identifier::SLUG_PATTERN),
        ];
    }

    /**
     * @return list<Field>
     */
    private static function review(): array
    {
        return [
            new Field('reports.review', FieldType::Group, '12-month review'),
            new Field('reports.review.interval_days', FieldType::Integer, 'Review every', 'A review is due when the last one is this many days old.', 15, min: 1, max: 90, unit: Field::UNIT_DAYS),
            new Field('reports.review.ahead_ratio', FieldType::Number, 'Ahead from', 'Own sold occupancy over the market\'s at or above this is "ahead".', 1.2, min: 1, max: 5, unit: Field::UNIT_RATIO),
            new Field('reports.review.behind_ratio', FieldType::Number, 'Behind up to', 'Own sold occupancy over the market\'s at or below this is "behind".', 0.8, min: 0.1, max: 1, unit: Field::UNIT_RATIO),
            new Field('reports.review.early_market_percent', FieldType::Number, 'Too early below', 'A month the market sold less of is too early to compare.', 5, min: 0, max: 50, unit: Field::UNIT_PERCENT),
            new Field('reports.review.price_tags', FieldType::ListOf, 'Rooms of the average price', 'Tags of the rooms whose average sent price the review shows; empty for every room.', [], max: 20, unique: true),
            new Field('reports.review.price_tags.*', FieldType::Text, 'Tag', pattern: Identifier::SLUG_PATTERN),
        ];
    }

    /**
     * @return list<Field>
     */
    private static function forecast(): array
    {
        $fields = [
            new Field('reports.forecast', FieldType::Group, 'Revenue forecast', 'Scenario parameters over last year\'s market; the monthly inputs are typed on the targets screen.'),
            new Field('reports.forecast.scenarios', FieldType::Group, 'Scenarios'),
        ];
        foreach (['floor' => 'Floor scenario', 'base' => 'Base scenario', 'goal' => 'Goal scenario'] as $scenario => $label) {
            $key = 'reports.forecast.scenarios.' . $scenario;
            $fields[] = new Field($key, FieldType::Group, $label);
            $fields[] = new Field($key . '.growth', FieldType::Number, 'Growth over last year', 'Multiplies last year\'s market revenue.', 1.0, min: 0.5, max: 2, unit: Field::UNIT_RATIO);
            $fields[] = new Field($key . '.index', FieldType::Map, 'Index by property', 'How each property sells against the market (property id => index); 1 when missing.', [], max: self::MAX_PROPERTIES, pattern: Identifier::CODE_PATTERN);
            $fields[] = new Field($key . '.index.*', FieldType::Number, 'Index', min: 0.1, max: 5, unit: Field::UNIT_RATIO);
        }

        return [
            ...$fields,
            new Field('reports.forecast.realistic_growth', FieldType::Number, 'Growth of today and realistic', 'Growth used for today\'s forecast and the realistic target per property.', 1.0, min: 0.5, max: 2, unit: Field::UNIT_RATIO),
            new Field('reports.forecast.maximum_index', FieldType::Map, 'Maximum index by property', 'Index of the maximum forecast (property id => index); 1 when missing.', [], max: self::MAX_PROPERTIES, pattern: Identifier::CODE_PATTERN),
            new Field('reports.forecast.maximum_index.*', FieldType::Number, 'Index', min: 0.1, max: 5, unit: Field::UNIT_RATIO),
            new Field('reports.forecast.high_months', FieldType::ListOf, 'High-season months', 'Month numbers (1-12) whose minimum is the high-season one when no monthly minimum is typed.', [], max: 12, unique: true),
            new Field('reports.forecast.high_months.*', FieldType::Integer, 'Month', min: 1, max: 12),
            new Field('reports.forecast.season_minimum', FieldType::Group, 'Season minimums', 'The owner\'s minimum revenue per month when a month has none typed.'),
            new Field('reports.forecast.season_minimum.high', FieldType::Map, 'High season', 'Property id => minimum revenue of a high-season month.', [], max: self::MAX_PROPERTIES, pattern: Identifier::CODE_PATTERN),
            new Field('reports.forecast.season_minimum.high.*', FieldType::Number, 'Minimum', min: 0, max: 100000000, unit: Field::UNIT_MONEY),
            new Field('reports.forecast.season_minimum.low', FieldType::Map, 'Low season', 'Property id => minimum revenue of any other month.', [], max: self::MAX_PROPERTIES, pattern: Identifier::CODE_PATTERN),
            new Field('reports.forecast.season_minimum.low.*', FieldType::Number, 'Minimum', min: 0, max: 100000000, unit: Field::UNIT_MONEY),
        ];
    }
}
