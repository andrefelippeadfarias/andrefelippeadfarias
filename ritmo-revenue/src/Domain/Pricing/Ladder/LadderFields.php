<?php

declare(strict_types=1);

namespace Ritmo\Domain\Pricing\Ladder;

use Ritmo\Domain\Config\Field;
use Ritmo\Domain\Config\FieldType;
use Ritmo\Domain\Model\Identifier;
use Ritmo\Domain\Orders\OwnerOrder;

/**
 * The definitions of the "ladder" section (type, range, neutral default, label, help), in the
 * format of the rule set schema.
 */
final class LadderFields
{
    private const S = 'ladder.strategies.*';
    private const R = 'ladder.strategies.*.rules.*';
    private const CODE = '/^' . Identifier::CODE_BODY . '$/';
    private const SLUG = Identifier::SLUG_PATTERN;

    /**
     * @return list<Field>
     */
    public static function all(): array
    {
        return [
            new Field('ladder', FieldType::Group, 'Sales ladder', 'Price selected nights by a market percentile that climbs as units sell.'),
            new Field('ladder.enabled', FieldType::Boolean, 'Sales ladder', 'Turn every ladder strategy on or off.', false),
            new Field('ladder.strategies', FieldType::ListOf, 'Ladder strategies', 'Each strategy covers a period and a set of night types and rooms.', [], max: 20),
            new Field(self::S, FieldType::Group, 'Strategy', required: true),
            ...self::strategy(),
            ...self::steps(),
            ...self::rules(),
            ...self::relations(),
            ...self::lastMinute(),
            ...self::proportional(),
        ];
    }

    /**
     * @return list<Field>
     */
    private static function strategy(): array
    {
        $s = self::S;

        return [
            new Field("$s.id", FieldType::Text, 'Identifier', 'Used in reasons and logs.', required: true, pattern: '/^[a-z0-9-]{1,64}$/'),
            new Field("$s.label", FieldType::Text, 'Name', 'Shown in the grid.', '', max: 80),
            new Field("$s.enabled", FieldType::Boolean, 'Active', 'Turning it off restores the rules that applied before.', false),
            new Field("$s.mode", FieldType::Choice, 'Mode', 'Stepped: percentile opening plus steps. Proportional: move toward a market top with each booking.', LadderStrategy::MODE_STEPPED, enum: [LadderStrategy::MODE_STEPPED, LadderStrategy::MODE_PROPORTIONAL]),
            new Field("$s.valid_from", FieldType::Date, 'First night', 'Leave empty for no start limit.', null, nullable: true),
            new Field("$s.valid_to", FieldType::Date, 'Last night', 'Leave empty for no end limit.', null, nullable: true),
            new Field("$s.activated_on", FieldType::Date, 'Activated on', 'Nights already inside the drop window on this date skip the drop. Empty: the date of the owner order, else the first night.', null, nullable: true),
            new Field("$s.authority_order_id", FieldType::Text, 'Owner order', 'When set, the strategy acts only while this order exists and covers the room and night.', null, nullable: true, pattern: OwnerOrder::ID_PATTERN),
            new Field("$s.lock_ceiling", FieldType::Boolean, 'Fix the price', 'Write floor and ceiling at the ladder price so the pricing algorithm cannot move it.', true),
            new Field("$s.weekend_floor_override", FieldType::Group, 'Weekend floor override', 'While the strategy acts, the weekend owner floor gives way to the quality standard.'),
            new Field("$s.weekend_floor_override.enabled", FieldType::Boolean, 'Replace weekend floor', 'Use the quality standard instead of the weekend owner floor on the nights below.', false),
            new Field("$s.weekend_floor_override.weekdays", FieldType::ListOf, 'Weekend nights', 'Nights (by check-in weekday, Monday = 0) the override covers.', [4, 5], max: 7, unique: true),
            new Field("$s.weekend_floor_override.weekdays.*", FieldType::Integer, 'Weekday', min: 0, max: 6),
        ];
    }

    /**
     * @return list<Field>
     */
    private static function steps(): array
    {
        $s = self::S;

        return [
            new Field("$s.step", FieldType::Group, 'Steps', 'How the price climbs as units sell.'),
            new Field("$s.step.trigger_fraction", FieldType::Number, 'Start steps after', 'Fraction of the units that sells at the opening price.', 0.5, min: 0, max: 1, exclusiveMin: true, unit: Field::UNIT_RATIO),
            new Field("$s.step.percent_per_unit", FieldType::Number, 'Step per unit (%)', 'Compound increase for each further unit sold.', 5.0, min: 0, max: 50, unit: Field::UNIT_PERCENT),
            new Field("$s.drop", FieldType::Group, 'Last-minute drop', 'Lower the price close to the night when few units sold.'),
            new Field("$s.drop.enabled", FieldType::Boolean, 'Last-minute drop', 'Lower the price close to the night when few units sold.', false),
            new Field("$s.drop.window_days", FieldType::Integer, 'Drop window (days)', 'Applies from this many days before the night down to the night itself.', 7, min: 0, max: 60, unit: Field::UNIT_DAYS),
            new Field("$s.drop.occupancy_below", FieldType::Number, 'Occupancy below', 'Drop only when less than this share of the room type is sold.', 0.6, min: 0, max: 1, unit: Field::UNIT_RATIO),
            new Field("$s.drop.percent", FieldType::Number, 'Drop (%)', 'Applied once, after the steps and the ceiling.', 10.0, min: 0, max: 50, unit: Field::UNIT_PERCENT),
            new Field("$s.drop.skip_inside_window_at_activation", FieldType::Boolean, 'Spare nights already close', 'Nights already inside the window when the strategy was activated keep the ladder price.', true),
        ];
    }

    /**
     * @return list<Field>
     */
    private static function rules(): array
    {
        $r = self::R;

        return [
            new Field('ladder.strategies.*.rules', FieldType::ListOf, 'Pricing rules', 'The first rule that matches the room and the night type prices it; nights no rule matches are left to the pace engine.', [], max: 50),
            new Field($r, FieldType::Group, 'Rule', required: true),
            new Field("$r.night_types", FieldType::ListOf, 'Night types', 'Night-type labels of the market ruler this rule prices.', required: true, min: 1, max: 20, unique: true),
            new Field("$r.night_types.*", FieldType::Text, 'Night type', pattern: self::SLUG),
            new Field("$r.rooms", FieldType::ListOf, 'Rooms', 'Rooms this rule prices.', [], max: 50, unique: true),
            new Field("$r.rooms.*", FieldType::Text, 'Room', pattern: self::CODE),
            new Field("$r.room_tags", FieldType::ListOf, 'Room tags', 'Or every room carrying all these tags.', [], max: 10, unique: true),
            new Field("$r.room_tags.*", FieldType::Text, 'Tag', pattern: self::SLUG),
            new Field("$r.opening", FieldType::Group, 'Opening', 'Price of a room type with nothing sold.'),
            new Field("$r.opening.series", FieldType::Text, 'Opening series', 'Market price series of the ruler used for the opening price.', null, nullable: true, pattern: self::SLUG),
            new Field("$r.opening.percentile", FieldType::Number, 'Opening percentile', 'Position in the market; must be a point stored in the ruler.', 50.0, min: 0, max: 110),
            new Field("$r.opening.multiplier", FieldType::Number, 'Opening multiplier', 'Applied to the percentile value.', 1.0, min: 0.1, max: 5),
            new Field("$r.opening.cap", FieldType::Choice, 'Never above', 'Cap the opening at the reference price stored for the room and night.', 'none', enum: ['none', 'reference_snapshot']),
            new Field("$r.ceiling", FieldType::Group, 'Ceiling', 'Where the steps stop.'),
            new Field("$r.ceiling.series", FieldType::Text, 'Ceiling series', 'Empty: no steps above the opening.', null, nullable: true, pattern: self::SLUG),
            new Field("$r.ceiling.percentile", FieldType::Number, 'Ceiling percentile', 'Steps stop here.', 75.0, min: 0, max: 110),
            new Field("$r.derive", FieldType::Group, 'Derived price', 'Price the room from another room of the same night.'),
            new Field("$r.derive.from_room", FieldType::Text, 'Derived from', 'Empty: the rule opens on its own series.', null, nullable: true, pattern: self::CODE),
            new Field("$r.derive.multiplier", FieldType::Number, 'Derived multiplier', "Applied to the other room's price.", 1.0, min: 0.1, max: 5),
            new Field("$r.apply_quality_floor", FieldType::Boolean, 'Quality floor', 'Never below the quality standard (rooms with its tags).', false),
            new Field("$r.apply_drop", FieldType::Boolean, 'Allow drop', 'This rule takes the last-minute drop.', true),
        ];
    }

    /**
     * @return list<Field>
     */
    private static function relations(): array
    {
        $s = self::S;

        return [
            new Field("$s.relations", FieldType::Group, 'Room relations on ladder nights', 'Relations solved inside the ladder, on the nights it prices.'),
            new Field("$s.relations.between", FieldType::ListOf, 'Between', 'A room kept between two others.', [], max: 20),
            new Field("$s.relations.between.*", FieldType::Group, 'Between relation', required: true),
            new Field("$s.relations.between.*.room", FieldType::Text, 'Room', required: true, pattern: self::CODE),
            new Field("$s.relations.between.*.low", FieldType::Text, 'Not below', required: true, pattern: self::CODE),
            new Field("$s.relations.between.*.high", FieldType::Text, 'Toward', required: true, pattern: self::CODE),
            new Field("$s.relations.between.*.position", FieldType::Number, 'Position', 'How far toward the dearer room it may go (0.75 = three quarters of the way).', 0.75, min: 0, max: 1, unit: Field::UNIT_RATIO),
            new Field("$s.relations.premium", FieldType::ListOf, 'Premium over the dearest', 'A room kept above the dearest of others that still sell.', [], max: 20),
            new Field("$s.relations.premium.*", FieldType::Group, 'Premium relation', required: true),
            new Field("$s.relations.premium.*.room", FieldType::Text, 'Room', required: true, pattern: self::CODE),
            new Field("$s.relations.premium.*.over", FieldType::ListOf, 'Above', required: true, min: 1, max: 20, unique: true),
            new Field("$s.relations.premium.*.over.*", FieldType::Text, 'Room', pattern: self::CODE),
            new Field("$s.relations.premium.*.rate_weekend_or_holiday", FieldType::Number, 'Premium on weekends and holidays', '', 0.05, min: 0, max: 1, unit: Field::UNIT_RATIO),
            new Field("$s.relations.premium.*.rate_other", FieldType::Number, 'Premium on other nights', '', 0.2, min: 0, max: 1, unit: Field::UNIT_RATIO),
        ];
    }

    /**
     * @return list<Field>
     */
    private static function lastMinute(): array
    {
        $l = self::S . '.last_minute_floor';

        return [
            new Field($l, FieldType::Group, 'Last-minute market floor', 'On nights the ladder does not price, lower the floor to the market close to the night.'),
            new Field("$l.enabled", FieldType::Boolean, 'Last-minute market floor', 'Lower the floor to a market value close to the night.', false),
            new Field("$l.night_types", FieldType::ListOf, 'Night types', 'Ruler night-type labels the floor applies to.', [], max: 20, unique: true),
            new Field("$l.night_types.*", FieldType::Text, 'Night type', pattern: self::SLUG),
            new Field("$l.excluded_weekdays", FieldType::ListOf, 'Not on', 'Weekdays never covered (Monday = 0).', [], max: 7, unique: true),
            new Field("$l.excluded_weekdays.*", FieldType::Integer, 'Weekday', min: 0, max: 6),
            new Field("$l.skip_holidays", FieldType::Boolean, 'Skip holidays', 'Never on configured holiday nights.', true),
            new Field("$l.window_days", FieldType::Integer, 'Window (days)', 'From this many days before the night.', 7, min: 0, max: 30, unit: Field::UNIT_DAYS),
            new Field("$l.series", FieldType::Text, 'Series', 'Market series for the floor.', null, nullable: true, pattern: self::SLUG),
            new Field("$l.percentile", FieldType::Number, 'Percentile', 'Usually the median.', 50.0, min: 0, max: 110),
            new Field("$l.rooms", FieldType::Map, 'Rooms', 'Rooms covered and the multiplier of the market value.', [], max: 50, pattern: self::CODE),
            new Field("$l.rooms.*", FieldType::Number, 'Multiplier', min: 0.1, max: 5),
        ];
    }

    /**
     * @return list<Field>
     */
    private static function proportional(): array
    {
        $p = self::S . '.proportional';

        return [
            new Field($p, FieldType::Group, 'Proportional mode', 'Each booking moves the price toward a market top, in proportion to the units sold.'),
            new Field("$p.fallback_step_percent", FieldType::Number, 'Step without market data (%)', 'Used when no market top is known for the night.', 5.0, min: 0, max: 50, unit: Field::UNIT_PERCENT),
            new Field("$p.band_room", FieldType::Text, 'Discount room', 'A room priced in a band below other rooms.', null, nullable: true, pattern: self::CODE),
            new Field("$p.band_reference_room", FieldType::Text, 'Discount room reference', 'Its target is a share of this room\'s target.', null, nullable: true, pattern: self::CODE),
            new Field("$p.band_factor", FieldType::Number, 'Discount room factor', 'Target of the discount room relative to its reference room.', 0.9, min: 0.1, max: 1, unit: Field::UNIT_RATIO),
            new Field("$p.band_cap_rooms", FieldType::ListOf, 'Discount room cap', 'The discount room stays under the cheapest of these rooms that still sells.', [], max: 20, unique: true),
            new Field("$p.band_cap_rooms.*", FieldType::Text, 'Room', pattern: self::CODE),
            new Field("$p.band_cap_discount", FieldType::Number, 'Discount below the cap', '', 0.1, min: 0, max: 0.5, unit: Field::UNIT_RATIO),
            new Field("$p.temporary_floor", FieldType::Group, 'Temporary guest minimum', 'A lower cancellation floor until a date, in the price plan of the owner floors.'),
            new Field("$p.temporary_floor.amount", FieldType::Number, 'Amount', '', null, nullable: true, min: 0, max: 1000000, exclusiveMin: true, unit: Field::UNIT_MONEY),
            new Field("$p.temporary_floor.until", FieldType::Date, 'Until', '', null, nullable: true),
        ];
    }
}
