<?php

declare(strict_types=1);

namespace Ritmo\Domain\Calendar\Stay;

use Ritmo\Domain\Config\Field;
use Ritmo\Domain\Config\FieldType;
use Ritmo\Domain\Model\Identifier;

/**
 * The definitions of the "stay" section of the rule set: type, range, neutral default and help of
 * every field. The defaults switch every stay rule off.
 */
final class StayFields
{
    public const ROOT = 'stay';

    /** Most room codes (or property ids) one list or map of the section may name. */
    private const MAX_ROOMS = 200;

    private const WEEKDAY_KEY = '/^[0-6]$/';

    private const CLOSURES = ['no_checkin', 'no_checkout', 'no_checkin_checkout'];

    private const RESTRICTIONS = ['none', 'no_checkin', 'no_checkout', 'no_checkin_checkout'];

    /**
     * @return list<Field>
     */
    public static function all(): array
    {
        return [
            new Field('stay', FieldType::Group, 'Stay and arrival/departure', 'Minimum stay by arrival day, closed days, orphan nights, packages and seasons.'),
            ...self::arrivalRule(),
            new Field('stay.orphan', FieldType::Group, 'Orphan night', 'A free night whose next night is sold out.'),
            new Field('stay.orphan.next_night', FieldType::Boolean, 'Sell orphan nights for one night', 'A free night with the next night sold out takes a one-night stay (never in a package).', false),
            new Field('stay.orphan.on_holidays', FieldType::Boolean, 'Also in holiday periods', 'Holiday nights and their eves may take the one-night orphan stay too.', false),
            new Field('stay.holiday_min_stay', FieldType::Integer, 'Holiday stay when the period has none', 'Minimum stay of a holiday night whose period and season set none.', 1, min: 1, max: 30, unit: Field::UNIT_NIGHTS),
            ...self::closedDays(),
            new Field('stay.open_days', FieldType::ListOf, 'Weekdays always open', 'Weekdays whose arrival and departure stay open in the listed rooms: restrictions found there are cleared.', [], max: 7),
            new Field('stay.open_days.*', FieldType::Group, 'Open weekday'),
            new Field('stay.open_days.*.weekday', FieldType::Integer, 'Weekday', 'Monday = 0 ... Sunday = 6.', required: true, min: 0, max: 6),
            self::rooms('stay.open_days.*.rooms', 'Rooms', 'Room codes that keep this weekday open.', 1),
            new Field('stay.open_days.*.rooms.*', FieldType::Text, 'Room code', pattern: Identifier::CODE_PATTERN),
            ...self::packages(),
            ...self::seasons(),
            new Field('stay.pricing_sync', FieldType::Group, 'Pricing system pushes to the PMS', 'Fields the pricing system writes over the PMS; changes of these fields go to both systems. "*" means every room.'),
            self::rooms('stay.pricing_sync.min_stay_rooms', 'Minimum stay pushed for', 'Rooms whose minimum stay the pricing system pushes to the PMS.'),
            new Field('stay.pricing_sync.min_stay_rooms.*', FieldType::Text, 'Room code', pattern: self::roomOrAll()),
            self::rooms('stay.pricing_sync.max_stay_rooms', 'Maximum stay pushed for', 'Rooms whose maximum stay the pricing system pushes to the PMS.'),
            new Field('stay.pricing_sync.max_stay_rooms.*', FieldType::Text, 'Room code', pattern: self::roomOrAll()),
            self::rooms('stay.pricing_sync.arrival_departure_rooms', 'Arrival/departure pushed for', 'Rooms whose arrival and departure days the pricing system pushes to the PMS.'),
            new Field('stay.pricing_sync.arrival_departure_rooms.*', FieldType::Text, 'Room code', pattern: self::roomOrAll()),
            new Field('stay.keep_reason_markers', FieldType::ListOf, 'Reasons to keep', 'A date rule whose reason contains one of these texts keeps its reason when only its stay changes.', [], max: 20, unique: true),
            new Field('stay.keep_reason_markers.*', FieldType::Text, 'Text', min: 1, max: 80),
            new Field('stay.batch', FieldType::Group, 'PMS batch', 'How the stay changes are sent to the PMS.'),
            new Field('stay.batch.urgent_days', FieldType::Integer, 'Urgent window (days)', 'Changes from today to this many days ahead go first.', 14, min: 0, max: 60, unit: Field::UNIT_DAYS),
            new Field('stay.batch.max_ranges', FieldType::Integer, 'Ranges per request', 'Most date ranges sent to the PMS in one request.', 100, min: 1, max: 1000),
        ];
    }

    /**
     * @return list<Field>
     */
    private static function arrivalRule(): array
    {
        $week = ['0' => 1, '1' => 1, '2' => 1, '3' => 1, '4' => 1, '5' => 1, '6' => 1];

        return [
            new Field('stay.arrival_rule', FieldType::Group, 'Minimum stay by arrival day', 'The minimum stay of a night is the one of its weekday, as the night of arrival.'),
            new Field('stay.arrival_rule.enabled', FieldType::Boolean, 'On', 'Apply the minimum stay by arrival day.', false),
            new Field('stay.arrival_rule.valid_from', FieldType::Date, 'From night', 'First night the rule applies to; empty for no limit.', null, nullable: true),
            new Field('stay.arrival_rule.valid_to', FieldType::Date, 'To night', 'Last night the rule applies to; empty for no limit.', null, nullable: true),
            new Field('stay.arrival_rule.by_weekday', FieldType::Map, 'Nights by weekday', 'Minimum stay for an arrival on each weekday (Monday = 0 ... Sunday = 6).', $week, pattern: self::WEEKDAY_KEY, requiredKeys: array_keys($week), max: 7),
            self::nights('stay.arrival_rule.by_weekday.*'),
            new Field('stay.arrival_rule.properties', FieldType::Map, 'By property', 'Weekdays whose stay differs in a property (property id => weekday => nights).', [], max: self::MAX_ROOMS, pattern: Identifier::CODE_PATTERN),
            new Field('stay.arrival_rule.properties.*', FieldType::Map, 'Property weekdays', max: 7, pattern: self::WEEKDAY_KEY),
            self::nights('stay.arrival_rule.properties.*.*'),
            new Field('stay.arrival_rule.rooms', FieldType::Map, 'By room', 'Weekdays whose stay differs in a room (room code => weekday => nights); the room wins over its property.', [], max: self::MAX_ROOMS, pattern: Identifier::CODE_PATTERN),
            new Field('stay.arrival_rule.rooms.*', FieldType::Map, 'Room weekdays', max: 7, pattern: self::WEEKDAY_KEY),
            self::nights('stay.arrival_rule.rooms.*.*'),
            new Field('stay.arrival_rule.held_weekdays', FieldType::Map, 'Held weekdays', 'Weekdays of a room the rule leaves as they are until a decision (room code => weekdays).', [], max: self::MAX_ROOMS, pattern: Identifier::CODE_PATTERN),
            new Field('stay.arrival_rule.held_weekdays.*', FieldType::ListOf, 'Weekdays', max: 7, unique: true),
            new Field('stay.arrival_rule.held_weekdays.*.*', FieldType::Integer, 'Weekday', min: 0, max: 6),
        ];
    }

    /**
     * @return list<Field>
     */
    private static function closedDays(): array
    {
        return [
            new Field('stay.closed_days', FieldType::ListOf, 'Closed weekdays', 'Weekdays closed for arrival and/or departure in the PMS.', [], max: 7),
            new Field('stay.closed_days.*', FieldType::Group, 'Closed weekday'),
            new Field('stay.closed_days.*.weekday', FieldType::Integer, 'Weekday', 'Monday = 0 ... Sunday = 6.', required: true, min: 0, max: 6),
            new Field('stay.closed_days.*.restriction', FieldType::Choice, 'Closed for', 'Arrival, departure or both.', 'no_checkin_checkout', enum: self::CLOSURES),
            self::rooms('stay.closed_days.*.rooms', 'Rooms', 'Room codes the closure applies to; empty for every room.'),
            new Field('stay.closed_days.*.rooms.*', FieldType::Text, 'Room code', pattern: Identifier::CODE_PATTERN),
            self::rooms('stay.closed_days.*.except_rooms', 'Except rooms', 'Room codes the closure skips.'),
            new Field('stay.closed_days.*.except_rooms.*', FieldType::Text, 'Room code', pattern: Identifier::CODE_PATTERN),
            new Field('stay.closed_days.*.min_stay', FieldType::Integer, 'Own minimum stay', 'Minimum stay of the closed day; empty to let the arrival rule decide it.', null, nullable: true, min: 1, max: 30, unit: Field::UNIT_NIGHTS),
            new Field('stay.closed_days.*.open_dates', FieldType::Map, 'Dates with their own restriction', 'Dates of this weekday with another restriction (date => restriction), for example a festive date open.', [], max: 366, pattern: '/^\d{4}-\d{2}-\d{2}$/'),
            new Field('stay.closed_days.*.open_dates.*', FieldType::Choice, 'Restriction', enum: self::RESTRICTIONS),
            new Field('stay.closed_days.*.orphan_side', FieldType::Group, 'Orphan side', 'Opens the closed day when it or the night before is sold out.'),
            new Field('stay.closed_days.*.orphan_side.enabled', FieldType::Boolean, 'On', 'Open the side of the closed day that only one night can sell.', false),
            new Field('stay.closed_days.*.orphan_side.on_holidays', FieldType::Boolean, 'Also in holiday periods', 'The one-night stay of the free closed day applies in holiday periods too.', false),
            new Field('stay.closed_days.*.orphan_side.when_closed_day_sold_out', FieldType::Choice, 'Closed day sold out', 'Restriction of the closed day when it is sold out and the night before is free.', 'no_checkin', enum: self::RESTRICTIONS),
            new Field('stay.closed_days.*.orphan_side.when_previous_sold_out', FieldType::Choice, 'Night before sold out', 'Restriction of the closed day when the night before is sold out (or past) and it is free.', 'no_checkout', enum: self::RESTRICTIONS),
        ];
    }

    /**
     * @return list<Field>
     */
    private static function packages(): array
    {
        return [
            new Field('stay.packages', FieldType::ListOf, 'Closed packages', 'Dates with their own minimum and maximum stay and arrival/departure.', [], max: 50),
            new Field('stay.packages.*', FieldType::Group, 'Package'),
            new Field('stay.packages.*.name', FieldType::Text, 'Name', required: true, min: 1, max: 120),
            self::rooms('stay.packages.*.rooms', 'Rooms', 'Room codes of the package; empty for every room.'),
            new Field('stay.packages.*.rooms.*', FieldType::Text, 'Room code', pattern: Identifier::CODE_PATTERN),
            new Field('stay.packages.*.nights', FieldType::ListOf, 'Nights', required: true, min: 1, max: 366),
            new Field('stay.packages.*.nights.*', FieldType::Group, 'Night'),
            new Field('stay.packages.*.nights.*.date', FieldType::Date, 'Night', required: true),
            new Field('stay.packages.*.nights.*.min_stay', FieldType::Integer, 'Minimum stay', '', null, nullable: true, min: 1, max: 30, unit: Field::UNIT_NIGHTS),
            new Field('stay.packages.*.nights.*.max_stay', FieldType::Integer, 'Maximum stay', '', null, nullable: true, min: 1, max: 365, unit: Field::UNIT_NIGHTS),
            new Field('stay.packages.*.nights.*.restriction', FieldType::Choice, 'Arrival/departure', '', null, nullable: true, enum: self::RESTRICTIONS),
        ];
    }

    /**
     * @return list<Field>
     */
    private static function seasons(): array
    {
        return [
            new Field('stay.seasons', FieldType::ListOf, 'Seasons', 'Periods with their own minimum stay; the first period covering a night is its season.', [], max: 400),
            new Field('stay.seasons.*', FieldType::Group, 'Season'),
            new Field('stay.seasons.*.name', FieldType::Text, 'Name', required: true, min: 1, max: 160),
            new Field('stay.seasons.*.start', FieldType::Date, 'First night', required: true),
            new Field('stay.seasons.*.end', FieldType::Date, 'Last night', required: true),
            new Field('stay.seasons.*.weekdays', FieldType::ListOf, 'Weekdays', 'Weekdays the period covers.', [0, 1, 2, 3, 4, 5, 6], max: 7, unique: true),
            new Field('stay.seasons.*.weekdays.*', FieldType::Integer, 'Weekday', min: 0, max: 6),
            new Field('stay.seasons.*.min_stay', FieldType::Integer, 'Minimum stay', 'Empty when the period sets no stay.', null, nullable: true, min: 1, max: 30, unit: Field::UNIT_NIGHTS),
        ];
    }

    private static function nights(string $key): Field
    {
        return new Field($key, FieldType::Integer, 'Nights', min: 1, max: 30, unit: Field::UNIT_NIGHTS);
    }

    private static function rooms(string $key, string $label, string $help, int $min = 0): Field
    {
        return new Field($key, FieldType::ListOf, $label, $help, $min === 0 ? [] : null, required: $min > 0, min: $min, max: self::MAX_ROOMS, unique: true);
    }

    private static function roomOrAll(): string
    {
        return '/^(\*|' . Identifier::CODE_BODY . ')$/';
    }
}
