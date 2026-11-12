<?php

declare(strict_types=1);

namespace Ritmo\Domain\Relations\Config;

use Ritmo\Domain\Config\Field;
use Ritmo\Domain\Config\FieldType;
use Ritmo\Domain\Model\Identifier;
use Ritmo\Domain\Model\NightType;
use Ritmo\Domain\Model\PricePlan;
use Ritmo\Domain\Relations\BlockedNights;
use Ritmo\Domain\Relations\MemberPolicy;
use Ritmo\Domain\Relations\Params\BetweenMode;
use Ritmo\Domain\Relations\Params\DeriveMinimum;
use Ritmo\Domain\Relations\Params\EmptySetPolicy;
use Ritmo\Domain\Relations\Params\SwitchAction;
use Ritmo\Domain\Relations\Params\TriggerUnit;
use Ritmo\Domain\Relations\Params\WriteMode;
use Ritmo\Domain\Relations\RecheckMode;
use Ritmo\Domain\Relations\Relation;
use Ritmo\Domain\Relations\RelationScope;
use Ritmo\Domain\Relations\RelationStage;
use Ritmo\Domain\Relations\RelationType;
use Ritmo\Domain\Relations\ScanMode;
use Ritmo\Domain\Relations\StrategyNights;

/**
 * The description of every relations field (type, range, neutral default, label, help), for the
 * forms and the rule set schema. Each list is a consistent tree on its own (parents first):
 *
 * - {@see general()}: the "relations" group without its items (keys as in the rule set);
 * - {@see relation()}: the common fields of one relation (keys relative to the item);
 * - {@see params()}: the parameters of one relation type (keys relative to "params");
 * - {@see priceBand()}: the fields of one price band (keys relative to the item).
 */
final class RelationFields
{
    private const MONEY = 1000000;

    /**
     * @return list<Field>
     */
    public static function general(): array
    {
        return [
            new Field('relations', FieldType::Group, 'Relations between rooms', 'Rules that keep the prices of the room types of a property in order.'),
            new Field('relations.tolerance', FieldType::Group, 'Tolerances'),
            new Field('relations.tolerance.trigger_amount', FieldType::Number, 'Smallest gap worth acting on', 'A relation only acts when the price is off by more than this amount.', 1.0, min: 0, max: 1000, unit: Field::UNIT_MONEY),
            new Field('relations.tolerance.audit_base_percent', FieldType::Number, 'Audit tolerance, share of the base', 'Prices are written as whole percents of the listing base; one step is 1% of it.', 1.0, min: 0, max: 10, unit: Field::UNIT_PERCENT),
            new Field('relations.tolerance.audit_amount', FieldType::Number, 'Audit tolerance, fixed part', 'Added to the share of the base.', 1.0, min: 0, max: 1000, unit: Field::UNIT_MONEY),
            new Field('relations.scan_horizon_days', FieldType::Integer, 'Nights checked every full run', 'Relations set to check every night look this many days ahead.', 60, min: 0, max: 365, unit: Field::UNIT_DAYS),
            new Field('relations.audit_horizon_days', FieldType::Integer, 'Nights covered by the audit', 'How far the daily audit of the current prices looks.', 366, min: 0, max: 730, unit: Field::UNIT_DAYS),
            new Field('relations.blocked_nights', FieldType::Choice, 'Rooms closed in the PMS', 'Exclude: a room closed without a reservation is neither adjusted nor used as a reference. Ignore: only sold-out rooms leave the comparison (the band and the occupancy switch always exclude them).', BlockedNights::Exclude->value, enum: self::values(BlockedNights::cases())),
            new Field('relations.rounding_recheck', FieldType::Group, 'Re-check after rounding'),
            new Field('relations.rounding_recheck.mode', FieldType::Choice, 'Re-check mode', 'Convert the percents back to currency and check the order of the rooms again. Fix moves the dependent room by whole percent steps; warn only reports.', RecheckMode::Fix->value, enum: self::values(RecheckMode::cases())),
            new Field('relations.rounding_recheck.epsilon_amount', FieldType::Number, 'Re-check precision', 'Differences below this are ignored.', 0.005, min: 0, max: 1, unit: Field::UNIT_MONEY),
        ];
    }

    /**
     * @return list<Field>
     */
    public static function relation(): array
    {
        return [
            new Field('id', FieldType::Text, 'Id', 'Stable key; other relations and owner orders refer to it.', required: true, min: 1, max: 64, pattern: Relation::ID_PATTERN),
            new Field('label', FieldType::Text, 'Name', 'Shown in the report.', '', max: 120),
            new Field('enabled', FieldType::Boolean, 'On', '', true),
            new Field('property', FieldType::Text, 'Property', '', required: true, pattern: Identifier::CODE_PATTERN),
            new Field('type', FieldType::Choice, 'Kind of relation', '', required: true, enum: self::values(RelationType::cases())),
            new Field('target', FieldType::Text, 'Room adjusted', 'The room whose floor and ceiling the relation moves.', required: true, pattern: Identifier::CODE_PATTERN),
            new Field('priority', FieldType::Integer, 'Order', 'Lower runs first among relations of the same level.', Relation::DEFAULT_PRIORITY, min: 0, max: 1000),
            new Field('stages', FieldType::ListOf, 'Where it runs', 'Strategy (inside the sales ladder), coherence (after the engine), audit (daily check of the current prices), validate (plan validator).', [RelationStage::Coherence->value], min: 1, max: 4, unique: true),
            new Field('stages.*', FieldType::Choice, 'Stage', required: true, enum: self::values(RelationStage::cases())),
            new Field('scan', FieldType::Choice, 'Nights checked', 'Touched: only nights the engine changed in this run. Horizon: every night of the scan horizon.', ScanMode::Horizon->value, enum: self::values(ScanMode::cases())),
            new Field('strategy_nights', FieldType::Choice, 'Nights priced by the sales ladder', 'Skip when the ladder already applies the relation itself.', StrategyNights::Apply->value, enum: self::values(StrategyNights::cases())),
            new Field('scope', FieldType::Group, 'Nights it applies to'),
            new Field('scope.night_types', FieldType::ListOf, 'Night types', 'Empty: every night type.', [], unique: true),
            new Field('scope.night_types.*', FieldType::Choice, 'Night type', required: true, enum: self::values(NightType::cases())),
            new Field('scope.weekdays', FieldType::ListOf, 'Nights of the week', '0 is the Monday night. Empty: every night.', [], max: 7, unique: true),
            new Field('scope.weekdays.*', FieldType::Integer, 'Night of the week', required: true, min: 0, max: 6),
            new Field('scope.periods', FieldType::ListOf, 'Only in these periods', 'First and last night. Empty: always.', [], max: 50),
            new Field('scope.periods.*', FieldType::Group, 'Period', required: true),
            new Field('scope.periods.*.start', FieldType::Date, 'First night', required: true),
            new Field('scope.periods.*.end', FieldType::Date, 'Last night', required: true),
            new Field('scope.exclude_periods', FieldType::ListOf, 'Except in these periods', '', [], max: 50),
            new Field('scope.exclude_periods.*', FieldType::Group, 'Period', required: true),
            new Field('scope.exclude_periods.*.start', FieldType::Date, 'First night', required: true),
            new Field('scope.exclude_periods.*.end', FieldType::Date, 'Last night', required: true),
            new Field('scope.min_days_out', FieldType::Integer, 'From this many days ahead', 'Empty: no limit.', null, nullable: true, min: 0, max: RelationScope::MAX_DAYS_OUT, unit: Field::UNIT_DAYS),
            new Field('scope.max_days_out', FieldType::Integer, 'Up to this many days ahead', 'Empty: no limit.', null, nullable: true, min: 0, max: RelationScope::MAX_DAYS_OUT, unit: Field::UNIT_DAYS),
            new Field('scope.requires_order', FieldType::Text, 'Only while this owner order is active', 'The relation is off without the order.', null, nullable: true, pattern: Relation::REF_PATTERN),
            new Field('order_ref', FieldType::Text, 'Owner order cited', 'Cited on the items the relation writes; the validator checks the order covers the room and the night.', null, nullable: true, pattern: Relation::REF_PATTERN),
        ];
    }

    /**
     * @return list<Field>
     */
    public static function params(RelationType $type): array
    {
        return match ($type) {
            RelationType::AtLeast => self::atLeast(),
            RelationType::Between => self::between(),
            RelationType::PremiumOverMax => self::premium(),
            RelationType::DiscountBand => self::band(),
            RelationType::OccupancySwitch => self::occupancySwitch(),
        };
    }

    /**
     * @return list<Field>
     */
    public static function priceBand(): array
    {
        return [
            new Field('id', FieldType::Text, 'Id', '', required: true, pattern: Relation::ID_PATTERN),
            new Field('label', FieldType::Text, 'Name', '', '', max: 120),
            new Field('enabled', FieldType::Boolean, 'On', '', true),
            new Field('property', FieldType::Text, 'Property', '', required: true, pattern: Identifier::CODE_PATTERN),
            new Field('room', FieldType::Text, 'Room', '', required: true, pattern: Identifier::CODE_PATTERN),
            new Field('night_types', FieldType::ListOf, 'Night types', 'A holiday night gets the band only when holiday is listed.', [], unique: true),
            new Field('night_types.*', FieldType::Choice, 'Night type', required: true, enum: self::values(NightType::cases())),
            new Field('price_plan', FieldType::Choice, 'Amounts in', 'Price plan of the amounts; they are converted to the sent plan with the measured factors.', PricePlan::PublicRate->value, enum: self::values(PricePlan::cases())),
            new Field('min_amount', FieldType::Number, 'Lowest price', '', required: true, min: 0, max: self::MONEY, exclusiveMin: true, unit: Field::UNIT_MONEY),
            new Field('max_amount', FieldType::Number, 'Highest price', '', required: true, min: 0, max: self::MONEY, exclusiveMin: true, unit: Field::UNIT_MONEY),
            new Field('max_days_out', FieldType::Integer, 'Horizon', 'A band measured today is not copied further ahead than this.', 60, min: 0, max: 730, unit: Field::UNIT_DAYS),
            new Field('skip_on_strategy_nights', FieldType::Boolean, 'Not on sales ladder nights', '', true),
        ];
    }

    /**
     * @return list<Field>
     */
    private static function atLeast(): array
    {
        return [
            new Field('reference', FieldType::Text, 'Reference room', '', required: true, pattern: Identifier::CODE_PATTERN),
            new Field('margin', FieldType::Group, 'Margin'),
            new Field('margin.type', FieldType::Choice, 'Margin in', '', 'percent', enum: ['percent', 'amount']),
            new Field('margin.value', FieldType::Number, 'Margin', '0 = at least the same price.', 0.0, min: -100000, max: 100000),
            new Field('reference_min_units_left', FieldType::Integer, 'Reference must still have', 'Units the reference needs for the relation to act; 2 = its last unit does not pull this room up.', 1, min: 0, max: 1000),
            new Field('derive', FieldType::Boolean, 'Price is derived', 'On sales ladder nights the room\'s price is the reference plus the margin.', false),
            new Field('derive_min', FieldType::Choice, 'Lowest derived price', '', DeriveMinimum::None->value, enum: self::values(DeriveMinimum::cases())),
        ];
    }

    /**
     * @return list<Field>
     */
    private static function between(): array
    {
        return [
            new Field('mode', FieldType::Choice, 'Between', 'Two rooms, or two percents of one room.', BetweenMode::Path->value, enum: self::values(BetweenMode::cases())),
            new Field('low_reference', FieldType::Text, 'Lower room', 'Mode path.', null, nullable: true, pattern: Identifier::CODE_PATTERN),
            new Field('high_reference', FieldType::Text, 'Upper room', 'Mode path.', null, nullable: true, pattern: Identifier::CODE_PATTERN),
            new Field('position_min', FieldType::Number, 'Lowest point of the way', '0 = at the cheaper room.', 0.0, min: 0, max: 1, unit: Field::UNIT_RATIO),
            new Field('position_max', FieldType::Number, 'Highest point of the way', '0.75 = three quarters of the way to the dearer room.', 1.0, min: 0, max: 1, unit: Field::UNIT_RATIO),
            new Field('reference', FieldType::Text, 'Reference room', 'Mode percent_of.', null, nullable: true, pattern: Identifier::CODE_PATTERN),
            new Field('min_percent', FieldType::Number, 'Lowest percent', 'Mode percent_of.', null, nullable: true, min: 1, max: 1000, unit: Field::UNIT_PERCENT),
            new Field('max_percent', FieldType::Number, 'Highest percent', 'Mode percent_of.', null, nullable: true, min: 1, max: 1000, unit: Field::UNIT_PERCENT),
            self::policy('references', 'Compare with'),
        ];
    }

    /**
     * @return list<Field>
     */
    private static function premium(): array
    {
        $fields = [...self::set('set', 'Rooms compared'), new Field('premium_percent', FieldType::Group, 'Premium')];
        foreach (NightType::cases() as $type) {
            $fields[] = new Field('premium_percent.' . $type->value, FieldType::Number, sprintf('Premium on %s nights', $type->value), '', 0.0, min: 0, max: 500, unit: Field::UNIT_PERCENT);
        }
        $fields[] = new Field('epsilon_amount', FieldType::Number, 'Strictly above by', 'Added to the floor so the room is above, not equal.', 0.0, min: 0, max: 100, unit: Field::UNIT_MONEY);
        $fields[] = self::policy('members', 'Compare with');

        return $fields;
    }

    /**
     * @return list<Field>
     */
    private static function band(): array
    {
        return [
            ...self::set('set', 'Rooms compared'),
            new Field('upper_percent', FieldType::Number, 'Highest share of the reference', 'The reference is the cheapest free room of the set.', required: true, min: 1, max: 100, unit: Field::UNIT_PERCENT),
            new Field('lower_percent', FieldType::Number, 'Lowest share of the reference', 'Not above the highest share.', required: true, min: 1, max: 100, unit: Field::UNIT_PERCENT),
            new Field('reference_rounding_margin', FieldType::Number, 'Margin for the reference\'s own rounding', 'The reference moves in steps of 1% of its base.', 0.0, min: 0, max: 1000, unit: Field::UNIT_MONEY),
            new Field('lower_extra_amount', FieldType::Number, 'Extra on the lower edge', '', 0.0, min: 0, max: 100, unit: Field::UNIT_MONEY),
            self::policy('members', 'Compare with'),
            new Field('on_empty_set', FieldType::Choice, 'When every room of the set is sold', 'Skip: this room keeps its own price.', EmptySetPolicy::Skip->value, enum: self::values(EmptySetPolicy::cases())),
            new Field('down_only_night_types', FieldType::ListOf, 'Only lower on', 'On these nights the band never raises the room and stops at its floor.', [], unique: true),
            new Field('down_only_night_types.*', FieldType::Choice, 'Night type', required: true, enum: self::values(NightType::cases())),
            new Field('order_floor_yields', FieldType::Boolean, 'Owner floor orders give way', 'A floor set by an order above the band falls back to the lowest floor the room has.', true),
            new Field('follow_strategy_reference', FieldType::Boolean, 'Follow a ladder-priced reference', 'When a room of the set is priced by the sales ladder, go to the top of the band.', true),
            new Field('write_mode', FieldType::Choice, 'Write', 'Pin: floor and ceiling at the same value. Bounds: floor at the lower edge, ceiling at the upper edge.', WriteMode::Pin->value, enum: self::values(WriteMode::cases())),
        ];
    }

    /**
     * @return list<Field>
     */
    private static function occupancySwitch(): array
    {
        return [
            new Field('trigger', FieldType::Group, 'Trigger'),
            ...self::set('trigger.set', 'Rooms counted'),
            new Field('trigger.unit', FieldType::Choice, 'Threshold in', '', TriggerUnit::Percent->value, enum: self::values(TriggerUnit::cases())),
            new Field('trigger.threshold', FieldType::Number, 'Switch when sold reaches', 'Percent of the units of the set, or a number of units.', required: true, min: 0, max: 10000, exclusiveMin: true),
            new Field('then', FieldType::Group, 'Then'),
            new Field('then.kind', FieldType::Choice, 'The room goes to', 'A market price, or keeps its own price.', required: true, enum: self::values(SwitchAction::cases())),
            new Field('then.by_night_group', FieldType::Map, 'Market target per night group', 'Market segment and percentile for each night group (weekday, weekend, holiday, festive).', [], pattern: Identifier::SLUG_PATTERN),
            new Field('then.by_night_group.*', FieldType::Group, 'Market target', required: true),
            new Field('then.by_night_group.*.segment', FieldType::Text, 'Market segment', 'Competitor set and room category measured.', required: true, pattern: Identifier::SLUG_PATTERN),
            new Field('then.by_night_group.*.percentile', FieldType::Number, 'Percentile', '', required: true, min: 0, max: 100),
            new Field('cap_relation', FieldType::Text, 'Still capped by', 'A discount band of the same room whose upper edge caps the price while a room of its set is free.', null, nullable: true, pattern: Relation::ID_PATTERN),
            new Field('floor', FieldType::Choice, 'Never below', 'The safety floor of the room (and the holiday floor on holidays).', 'safety', enum: ['safety']),
            new Field('suspends', FieldType::ListOf, 'Replaces', 'Relations of the same room that do not run while the switch holds.', [], max: 20, unique: true),
            new Field('suspends.*', FieldType::Text, 'Relation', required: true, pattern: Relation::ID_PATTERN),
        ];
    }

    /**
     * @return list<Field>
     */
    private static function set(string $key, string $label): array
    {
        return [
            new Field($key, FieldType::Group, $label, 'Give the rooms by code, or by tags: every room of the property with all the tags, the adjusted room excluded.'),
            new Field($key . '.rooms', FieldType::ListOf, 'Rooms', '', null, nullable: true, max: 50, unique: true),
            new Field($key . '.rooms.*', FieldType::Text, 'Room', required: true, pattern: Identifier::CODE_PATTERN),
            new Field($key . '.room_tags', FieldType::ListOf, 'Room tags', '', null, nullable: true, max: 20, unique: true),
            new Field($key . '.room_tags.*', FieldType::Text, 'Tag', required: true, pattern: Identifier::SLUG_PATTERN),
        ];
    }

    private static function policy(string $key, string $label): Field
    {
        return new Field($key, FieldType::Choice, $label, 'Free only: a sold-out room leaves the comparison.', MemberPolicy::FreeOnly->value, enum: self::values(MemberPolicy::cases()));
    }

    /**
     * @param list<\BackedEnum> $cases
     *
     * @return list<string>
     */
    private static function values(array $cases): array
    {
        return array_map(static fn (\BackedEnum $case): string => (string) $case->value, $cases);
    }
}
