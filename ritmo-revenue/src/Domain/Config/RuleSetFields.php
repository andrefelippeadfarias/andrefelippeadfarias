<?php

declare(strict_types=1);

namespace Ritmo\Domain\Config;

use Ritmo\Domain\Model\Identifier;
use Ritmo\Domain\Model\NightType;
use Ritmo\Domain\Model\PricePlan;

/**
 * The field definitions of the rule set: every rule a customer can configure, with its type,
 * range, neutral default, label and help text.
 *
 * The defaults are deliberately generic. presets/default.json is this same set of defaults.
 */
final class RuleSetFields
{
    /** Occupancy windows, in days counted from today. */
    public const GOAL_WINDOWS = [7, 15, 30, 45, 60];

    /** Days before the night at which a pace curve has a point. */
    public const CURVE_DAYS = [60, 30, 14, 7, 3, 1, 0];

    /** Statuses of a target-percentile table, from the highest target to the lowest. */
    public const TARGET_STATUSES = ['ahead_near', 'ahead', 'on_pace', 'behind', 'very_behind'];

    /** Tier every rule set must define; properties without a tier of their own use it. */
    public const DEFAULT_TIER = 'standard';

    /** Entry of the price factor tables used by properties without a table of their own. */
    public const ANY_PROPERTY = '*';

    private const MAX_AMOUNT = 1000000;

    private const MIN_FACTOR = 0.05;

    private const MAX_FACTOR = 5;

    /**
     * @return list<Field> parents before their children
     */
    public static function all(): array
    {
        return array_merge(
            self::occupancy(),
            self::calendar(),
            self::paceCurves(),
            self::paceStatus(),
            self::paceTargets(),
            self::paceSteps(),
            self::floors(),
            self::priceFactors(),
            self::safety(),
        );
    }

    /**
     * @return list<string>
     */
    private static function nightTypes(): array
    {
        return array_map(static fn (NightType $type): string => $type->value, NightType::cases());
    }

    /**
     * @return list<Field>
     */
    private static function occupancy(): array
    {
        $defaults = [7 => 60.0, 15 => 45.0, 30 => 30.0, 45 => 20.0, 60 => 10.0];
        $fields = [
            new Field('occupancy', FieldType::Group, 'Occupancy'),
            new Field(
                'occupancy.goals',
                FieldType::Group,
                'Occupancy goals',
                'Share of the room nights that should be sold in each window counted from today. Each night is judged by the goal of the window it falls in.'
            ),
        ];
        foreach (self::GOAL_WINDOWS as $window) {
            $fields[] = new Field(
                key: 'occupancy.goals.' . $window,
                type: FieldType::Number,
                label: sprintf('Next %d days', $window),
                default: $defaults[$window],
                min: 0,
                max: 100,
                unit: Field::UNIT_PERCENT,
            );
        }

        return $fields;
    }

    /**
     * @return list<Field>
     */
    private static function calendar(): array
    {
        return [
            new Field('calendar', FieldType::Group, 'Calendar'),
            new Field(
                key: 'calendar.weekend_days',
                type: FieldType::ListOf,
                label: 'Weekend nights',
                help: 'Nights of the week priced as weekend. A night is named after its check-in day: 0 is Monday and 6 is Sunday, so 4 and 5 are the Friday and Saturday nights.',
                default: [4, 5],
                max: 7,
                unique: true,
            ),
            new Field('calendar.weekend_days.*', FieldType::Integer, 'Day of the week', required: true, min: 0, max: 6),
            new Field(
                key: 'calendar.holidays',
                type: FieldType::ListOf,
                label: 'Holidays and special periods',
                help: 'Periods priced with their own night type, minimum stay and floor. When periods overlap, the first one in the list wins.',
                default: [],
                max: 300,
            ),
            new Field('calendar.holidays.*', FieldType::Group, 'Period', required: true),
            new Field('calendar.holidays.*.name', FieldType::Text, 'Name', required: true, min: 1, max: 120),
            new Field('calendar.holidays.*.start', FieldType::Date, 'First night', required: true),
            new Field('calendar.holidays.*.end', FieldType::Date, 'Last night', 'The last night of the period, not the departure day.', required: true),
            new Field(
                key: 'calendar.holidays.*.night_type',
                type: FieldType::Choice,
                label: 'Night type',
                help: 'How the nights of the period are priced.',
                default: NightType::Holiday->value,
                enum: self::nightTypes(),
            ),
            new Field(
                key: 'calendar.holidays.*.min_stay',
                type: FieldType::Integer,
                label: 'Minimum stay',
                help: 'Leave empty for no minimum stay of its own.',
                nullable: true,
                min: 1,
                max: 30,
                unit: Field::UNIT_NIGHTS,
            ),
            new Field(
                key: 'calendar.holidays.*.floor',
                type: FieldType::Number,
                label: 'Floor',
                help: 'Lowest price of the nights of the period, in the price plan of the holiday floor. Leave empty to use only the general holiday floor.',
                nullable: true,
                min: 0,
                max: self::MAX_AMOUNT,
                unit: Field::UNIT_MONEY,
            ),
        ];
    }

    /**
     * @return list<Field>
     */
    private static function paceCurves(): array
    {
        $labels = [NightType::Weekday->value => 'Weekday nights', NightType::Weekend->value => 'Weekend nights', NightType::Holiday->value => 'Holiday nights'];
        $defaults = [
            NightType::Weekday->value => [60 => 5.0, 30 => 10.0, 14 => 20.0, 7 => 35.0, 3 => 50.0, 1 => 65.0, 0 => 100.0],
            NightType::Weekend->value => [60 => 10.0, 30 => 20.0, 14 => 35.0, 7 => 55.0, 3 => 70.0, 1 => 85.0, 0 => 100.0],
            NightType::Holiday->value => [60 => 15.0, 30 => 35.0, 14 => 55.0, 7 => 70.0, 3 => 85.0, 1 => 95.0, 0 => 100.0],
        ];
        $fields = [
            new Field('pace', FieldType::Group, 'Pace'),
            new Field(
                'pace.curves',
                FieldType::Group,
                'Expected sales curves',
                'Share of the units expected to be sold at each number of days before the night, per night type. Days in between are interpolated in a straight line. A curve never goes down as the night gets closer.'
            ),
        ];
        foreach (self::nightTypes() as $type) {
            $fields[] = new Field('pace.curves.' . $type, FieldType::Group, $labels[$type]);
            foreach (self::CURVE_DAYS as $days) {
                $fields[] = new Field(
                    key: sprintf('pace.curves.%s.%d', $type, $days),
                    type: FieldType::Number,
                    label: $days === 0 ? 'On the day' : sprintf('%d days before', $days),
                    default: $defaults[$type][$days],
                    min: 0,
                    max: 100,
                    unit: Field::UNIT_PERCENT,
                );
            }
        }

        return $fields;
    }

    /**
     * @return list<Field>
     */
    private static function paceStatus(): array
    {
        $zeroSoldDefaults = [NightType::Weekday->value => 5, NightType::Weekend->value => 10, NightType::Holiday->value => 14];
        $fields = [
            new Field(
                key: 'pace.band',
                type: FieldType::Number,
                label: 'Pace band',
                help: 'Points of difference between sold and expected that take a night out of "on pace": at or above +band it is ahead, at or below -band it is behind.',
                default: 15.0,
                min: 0,
                max: 100,
                unit: Field::UNIT_PERCENT,
            ),
            new Field(
                key: 'pace.very_behind',
                type: FieldType::Number,
                label: 'Very behind threshold',
                help: 'Points below the expected curve at which a night is very behind. Not lower than the pace band.',
                default: 25.0,
                min: 0,
                max: 100,
                unit: Field::UNIT_PERCENT,
            ),
            new Field(
                'pace.zero_sold_days',
                FieldType::Group,
                'Nothing sold close to the night',
                'A night with nothing sold at this many days before it, or fewer, is very behind whatever the curve says.'
            ),
        ];
        foreach (self::nightTypes() as $type) {
            $fields[] = new Field(
                key: 'pace.zero_sold_days.' . $type,
                type: FieldType::Integer,
                label: ucfirst($type) . ' nights',
                default: $zeroSoldDefaults[$type],
                min: 0,
                max: 365,
                unit: Field::UNIT_DAYS,
            );
        }

        return $fields;
    }

    /**
     * @return list<Field>
     */
    private static function paceTargets(): array
    {
        $statusLabels = [
            'ahead_near' => 'Ahead, close to the night',
            'ahead' => 'Ahead',
            'on_pace' => 'On pace',
            'behind' => 'Behind',
            'very_behind' => 'Very behind',
        ];
        $fields = [];
        $fields[] = new Field(
            key: 'pace.target_percentile',
            type: FieldType::Map,
            label: 'Target market percentile',
            help: 'Where in the market price range a night should sit, by pace status. Each tier is one table and a property uses the table of its tier. The tier "standard" must exist.',
            default: [
                self::DEFAULT_TIER => ['ahead_near' => 80.0, 'ahead' => 65.0, 'on_pace' => 50.0, 'behind' => 40.0, 'very_behind' => 30.0],
                'premium' => ['ahead_near' => 90.0, 'ahead' => 75.0, 'on_pace' => 60.0, 'behind' => 50.0, 'very_behind' => 40.0],
            ],
            min: 1,
            max: 12,
            pattern: Identifier::SLUG_PATTERN,
            requiredKeys: [self::DEFAULT_TIER],
        );
        $fields[] = new Field('pace.target_percentile.*', FieldType::Group, 'Tier', required: true);
        foreach (self::TARGET_STATUSES as $status) {
            $fields[] = new Field(
                key: 'pace.target_percentile.*.' . $status,
                type: FieldType::Number,
                label: $statusLabels[$status],
                required: true,
                min: 0,
                max: 150,
                unit: Field::UNIT_PERCENT,
            );
        }
        $fields[] = new Field(
            key: 'pace.ahead_near_days',
            type: FieldType::Integer,
            label: 'Close-to-the-night window',
            help: 'Up to this many days before the night, a night that is ahead uses the "ahead, close to the night" percentile.',
            default: 7,
            min: 0,
            max: 365,
            unit: Field::UNIT_DAYS,
        );

        return $fields;
    }

    /**
     * @return list<Field>
     */
    private static function paceSteps(): array
    {
        return [
            new Field(
                key: 'pace.k',
                type: FieldType::Number,
                label: 'Step strength',
                help: 'Share of the distance to the target price covered by one step (0.5 = half way).',
                default: 0.5,
                min: 0,
                max: 1,
                exclusiveMin: true,
                unit: Field::UNIT_RATIO,
            ),
            new Field(
                key: 'pace.max_step',
                type: FieldType::ListOf,
                label: 'Largest step by lead time',
                help: 'The largest price change of one step, depending on how many days are left. The row with the highest "from days" not above the lead time applies. A row starting at 0 days is required.',
                default: [
                    ['min_days' => 30, 'percent' => 5.0],
                    ['min_days' => 8, 'percent' => 10.0],
                    ['min_days' => 0, 'percent' => 15.0],
                ],
                min: 1,
                max: 12,
            ),
            new Field('pace.max_step.*', FieldType::Group, 'Lead time band', required: true),
            new Field('pace.max_step.*.min_days', FieldType::Integer, 'From days before', required: true, min: 0, max: 365, unit: Field::UNIT_DAYS),
            new Field(
                key: 'pace.max_step.*.percent',
                type: FieldType::Number,
                label: 'Largest step',
                required: true,
                min: 0,
                max: 100,
                exclusiveMin: true,
                unit: Field::UNIT_PERCENT,
            ),
            new Field(
                key: 'pace.min_n',
                type: FieldType::Integer,
                label: 'Smallest trusted sample',
                help: 'A market anchor built from fewer competitors than this is weak: steps are cut in half.',
                default: 5,
                min: 0,
                max: 1000,
            ),
            new Field(
                key: 'pace.max_age_days',
                type: FieldType::Integer,
                label: 'Oldest trusted sample',
                help: 'A market anchor older than this is weak: steps are cut in half.',
                default: 14,
                min: 0,
                max: 365,
                unit: Field::UNIT_DAYS,
            ),
            new Field(
                key: 'pace.tolerance',
                type: FieldType::Number,
                label: 'Smallest change worth writing',
                help: 'Relative price change below which no step is produced (0.02 = 2%).',
                default: 0.02,
                min: 0,
                max: 1,
                unit: Field::UNIT_RATIO,
            ),
            new Field(
                key: 'pace.protection_percentile',
                type: FieldType::Number,
                label: 'Sold-out protection percentile',
                help: 'Market percentile used as the floor of a sold-out night, so that a cancellation does not reopen it cheap.',
                default: 85.0,
                min: 0,
                max: 150,
                unit: Field::UNIT_PERCENT,
            ),
        ];
    }

    /**
     * @return list<Field>
     */
    private static function floors(): array
    {
        $plans = array_map(static fn (PricePlan $plan): string => $plan->value, PricePlan::cases());
        $planHelp = 'The price plan the amount is expressed in. It is converted to the sent price with the measured price factors.';
        $tagsHelp = 'The floor applies to rooms that have every tag listed. Leave empty for every room.';

        return [
            new Field('floors', FieldType::Group, 'Floors'),
            new Field(
                key: 'floors.fraction_of_reference_min',
                type: FieldType::Number,
                label: 'Safety limit',
                help: 'No price ever goes below this fraction of the reference minimum of the room (0.75 = 75%).',
                default: 0.75,
                min: 0,
                max: 1,
                unit: Field::UNIT_RATIO,
            ),
            new Field(
                key: 'floors.owner',
                type: FieldType::ListOf,
                label: 'Owner floors',
                help: 'Lowest prices set by the owner for a night type.',
                default: [],
                max: 50,
            ),
            new Field('floors.owner.*', FieldType::Group, 'Owner floor', required: true),
            new Field('floors.owner.*.night_type', FieldType::Choice, 'Night type', required: true, enum: self::nightTypes()),
            new Field(
                key: 'floors.owner.*.amount',
                type: FieldType::Number,
                label: 'Amount',
                required: true,
                min: 0,
                max: self::MAX_AMOUNT,
                exclusiveMin: true,
                unit: Field::UNIT_MONEY,
            ),
            new Field('floors.owner.*.price_plan', FieldType::Choice, 'Price plan', $planHelp, required: true, enum: $plans),
            new Field('floors.owner.*.room_tags', FieldType::ListOf, 'Only rooms tagged', $tagsHelp, default: [], max: 20, unique: true),
            new Field('floors.owner.*.room_tags.*', FieldType::Text, 'Tag', required: true, pattern: Identifier::SLUG_PATTERN),
            new Field(
                'floors.quality_floor',
                FieldType::Group,
                'Quality floor',
                'A general lowest price for the chosen night types. An amount of zero turns it off.'
            ),
            new Field('floors.quality_floor.amount', FieldType::Number, 'Amount', default: 0.0, min: 0, max: self::MAX_AMOUNT, unit: Field::UNIT_MONEY),
            new Field('floors.quality_floor.price_plan', FieldType::Choice, 'Price plan', $planHelp, default: PricePlan::Sent->value, enum: $plans),
            new Field(
                key: 'floors.quality_floor.night_types',
                type: FieldType::ListOf,
                label: 'Night types',
                default: [NightType::Weekend->value, NightType::Holiday->value],
                max: 3,
                unique: true,
            ),
            new Field('floors.quality_floor.night_types.*', FieldType::Choice, 'Night type', required: true, enum: self::nightTypes()),
            new Field('floors.quality_floor.room_tags', FieldType::ListOf, 'Only rooms tagged', $tagsHelp, default: [], max: 20, unique: true),
            new Field('floors.quality_floor.room_tags.*', FieldType::Text, 'Tag', required: true, pattern: Identifier::SLUG_PATTERN),
            new Field(
                'floors.holiday_floor',
                FieldType::Group,
                'Holiday floor',
                'Lowest price of any holiday night. A period can set a higher floor of its own. An amount of zero turns it off.'
            ),
            new Field('floors.holiday_floor.amount', FieldType::Number, 'Amount', default: 0.0, min: 0, max: self::MAX_AMOUNT, unit: Field::UNIT_MONEY),
            new Field('floors.holiday_floor.price_plan', FieldType::Choice, 'Price plan', $planHelp, default: PricePlan::Sent->value, enum: $plans),
        ];
    }

    /**
     * @return list<Field>
     */
    private static function priceFactors(): array
    {
        $planLabels = [PricePlan::PublicRate->value => 'Public price', PricePlan::Discounted->value => 'Discounted price'];
        $neutral = array_fill_keys(self::nightTypes(), 1.0);
        $fields = [
            new Field(
                'price_factors',
                FieldType::Group,
                'Price factors',
                'Measured ratio between a price on the sales channel and the price sent by the pricing system (plan price / sent price). 1 means the same price.'
            ),
            new Field(
                key: 'price_factors.properties',
                type: FieldType::Map,
                label: 'Factors by property',
                help: 'One table per property id. The entry "*" is required and is used by properties without a table of their own.',
                default: [self::ANY_PROPERTY => [PricePlan::PublicRate->value => $neutral, PricePlan::Discounted->value => $neutral]],
                min: 1,
                max: 100,
                pattern: '/^(\*|' . Identifier::CODE_BODY . ')$/',
                requiredKeys: [self::ANY_PROPERTY],
            ),
            new Field('price_factors.properties.*', FieldType::Group, 'Property', required: true),
        ];
        foreach ($planLabels as $plan => $label) {
            $fields[] = new Field('price_factors.properties.*.' . $plan, FieldType::Group, $label, required: true);
            foreach (self::nightTypes() as $type) {
                $fields[] = new Field(
                    key: sprintf('price_factors.properties.*.%s.%s', $plan, $type),
                    type: FieldType::Number,
                    label: ucfirst($type) . ' nights',
                    required: true,
                    min: self::MIN_FACTOR,
                    max: self::MAX_FACTOR,
                    unit: Field::UNIT_RATIO,
                );
            }
        }

        return array_merge($fields, [
            new Field(
                key: 'price_factors.room_overrides',
                type: FieldType::ListOf,
                label: 'Room exceptions',
                help: 'A room whose measured factor differs from the factor of its property.',
                default: [],
                max: 300,
            ),
            new Field('price_factors.room_overrides.*', FieldType::Group, 'Room exception', required: true),
            new Field('price_factors.room_overrides.*.room', FieldType::Text, 'Room type code', required: true, pattern: Identifier::CODE_PATTERN),
            new Field('price_factors.room_overrides.*.price_plan', FieldType::Choice, 'Price plan', required: true, enum: array_keys($planLabels)),
            new Field('price_factors.room_overrides.*.night_type', FieldType::Choice, 'Night type', required: true, enum: self::nightTypes()),
            new Field(
                key: 'price_factors.room_overrides.*.factor',
                type: FieldType::Number,
                label: 'Factor',
                required: true,
                min: self::MIN_FACTOR,
                max: self::MAX_FACTOR,
                unit: Field::UNIT_RATIO,
            ),
        ]);
    }

    /**
     * @return list<Field>
     */
    private static function safety(): array
    {
        return [
            new Field('safety', FieldType::Group, 'Safety'),
            new Field(
                key: 'safety.weekly_listing_change_max',
                type: FieldType::Number,
                label: 'Largest weekly change of a listing',
                help: 'Base, minimum and maximum of a listing never move more than this fraction within one week (0.1 = 10%).',
                default: 0.1,
                min: 0,
                max: 1,
                unit: Field::UNIT_RATIO,
            ),
            new Field(
                key: 'safety.never_raise_today',
                type: FieldType::Boolean,
                label: "Never raise tonight's price",
                help: 'Always on: the price of tonight is never increased.',
                default: true,
                locked: true,
            ),
        ];
    }
}
