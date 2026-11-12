<?php

declare(strict_types=1);

namespace Ritmo\Domain\Config;

/**
 * Where the rule set names a room type or a property, so that a preset can check every name
 * points at something it defines (a typo in a room code would silently switch a rule off).
 *
 * A pattern is a dotted path where "*" walks every item of a list or entry of a map and "{key}"
 * takes the keys of a map as the names. The value "*" means "every room" where a rule allows it.
 * The relations between rooms and the price bands are checked by Relations\RelationValidator
 * (cycles included).
 */
final class RuleReferences
{
    public const EVERY_ROOM = '*';

    /** Paths whose values (or map keys) are room type codes. */
    private const ROOMS = [
        'ladder.strategies.*.rules.*.rooms.*',
        'ladder.strategies.*.rules.*.derive.from_room',
        'ladder.strategies.*.relations.between.*.room',
        'ladder.strategies.*.relations.between.*.low',
        'ladder.strategies.*.relations.between.*.high',
        'ladder.strategies.*.relations.premium.*.room',
        'ladder.strategies.*.relations.premium.*.over.*',
        'ladder.strategies.*.last_minute_floor.rooms.{key}',
        'ladder.strategies.*.proportional.band_room',
        'ladder.strategies.*.proportional.band_reference_room',
        'ladder.strategies.*.proportional.band_cap_rooms.*',
        'stay.arrival_rule.rooms.{key}',
        'stay.arrival_rule.held_weekdays.{key}',
        'stay.closed_days.*.rooms.*',
        'stay.closed_days.*.except_rooms.*',
        'stay.open_days.*.rooms.*',
        'stay.packages.*.rooms.*',
        'stay.pricing_sync.min_stay_rooms.*',
        'stay.pricing_sync.max_stay_rooms.*',
        'stay.pricing_sync.arrival_departure_rooms.*',
        'plan.validator.no_discount_rooms.*',
        'plan.validator.last_minute.rooms.*',
        'plan.validator.last_minute.last_unit_only_rooms.*',
    ];

    /** Paths whose values (or map keys) are property ids. */
    private const PROPERTIES = [
        'stay.arrival_rule.properties.{key}',
    ];

    /**
     * The references of the rules that name nothing known.
     *
     * @param array<string, mixed> $rules     the rule set array
     * @param list<string>         $roomCodes
     * @param list<string>         $propertyIds
     *
     * @return list<ValidationError> paths relative to the rule set
     */
    public static function unknown(array $rules, array $roomCodes, array $propertyIds): array
    {
        $errors = [];
        foreach ([[self::ROOMS, $roomCodes, 'room type'], [self::PROPERTIES, $propertyIds, 'property']] as [$patterns, $known, $noun]) {
            foreach ($patterns as $pattern) {
                foreach (self::find($rules, explode('.', $pattern), '') as $path => $name) {
                    if ($name !== self::EVERY_ROOM && !in_array($name, $known, true)) {
                        $errors[] = new ValidationError($path, ValidationError::REFERENCE, sprintf('"%s" is not a %s of this preset', $name, $noun));
                    }
                }
            }
        }

        return $errors;
    }

    /**
     * @param list<string> $segments
     *
     * @return array<string, string> path => name found there
     */
    private static function find(mixed $value, array $segments, string $path): array
    {
        if ($segments === []) {
            return is_string($value) ? [$path => $value] : [];
        }
        if (!is_array($value)) {
            return [];
        }
        $segment = array_shift($segments);
        $join = static fn (int|string $key): string => $path === '' ? (string) $key : $path . '.' . $key;
        if ($segment === '{key}') {
            $found = [];
            foreach (array_keys($value) as $key) {
                $found[$join($key)] = (string) $key;
            }

            return $found;
        }
        if ($segment === '*') {
            $found = [];
            foreach ($value as $key => $entry) {
                $found += self::find($entry, $segments, $join($key));
            }

            return $found;
        }

        return array_key_exists($segment, $value) ? self::find($value[$segment], $segments, $join($segment)) : [];
    }
}
