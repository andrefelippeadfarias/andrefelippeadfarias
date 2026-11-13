<?php

declare(strict_types=1);

namespace Ritmo\Admin\Editors;

use Ritmo\Admin\Components\Money;
use Ritmo\Application\Rules\Percent;

/**
 * A room relation in one plain sentence (spec 05, 5.6), from the relation as the rules store it:
 * "Room B never costs less than Room A plus 10%." Rooms are named by their names; amounts in
 * money; shares as percents. Never a code.
 */
final class RelationText
{
    private function __construct()
    {
    }

    /**
     * @param array<string, mixed>  $relation  an item of relations.items
     * @param array<string, string> $roomNames room code => name
     */
    public static function describe(array $relation, array $roomNames, string $currency): string
    {
        $p = (array) ($relation['params'] ?? []);
        $target = self::room((string) ($relation['target'] ?? ''), $roomNames);

        return match ((string) ($relation['type'] ?? '')) {
            'at_least' => self::atLeast($target, $p, $roomNames, $currency),
            'between' => self::between($target, $p, $roomNames),
            'premium_over_max' => self::premium($target, $p, $roomNames),
            'discount_band' => sprintf(
                /* translators: 1: room, 2: lowest percent, 3: highest percent, 4: rooms */
                __('%1$s costs between %2$s%% and %3$s%% of the cheapest free room among %4$s.', 'ritmo-revenue'),
                $target,
                self::number($p['lower_percent'] ?? 0),
                self::number($p['upper_percent'] ?? 0),
                self::set((array) ($p['set'] ?? []), $roomNames)
            ),
            'occupancy_switch' => self::occupancySwitch($target, $p, $roomNames),
            default => __('A relation of an unknown kind: the engine ignores it.', 'ritmo-revenue'),
        };
    }

    /**
     * The sentence of a loop the relations would make.
     *
     * @param list<string>          $walk      the rooms of the loop, the first repeated at the end
     * @param list<string>          $labels    the relations that link them
     * @param array<string, string> $roomNames
     */
    public static function loop(array $walk, array $labels, array $roomNames): string
    {
        $steps = [];
        for ($i = count($walk) - 1; $i > 0; --$i) {
            /* translators: 1: a room, 2: the room it depends on */
            $steps[] = sprintf(__('%1$s depends on %2$s', 'ritmo-revenue'), self::room($walk[$i], $roomNames), self::room($walk[$i - 1], $roomNames));
        }

        return sprintf(
            /* translators: 1: the rooms depending on each other, 2: the relations involved */
            __('This would make a loop: %1$s (relations %2$s). Turn one of them off or change it.', 'ritmo-revenue'),
            self::join($steps),
            self::join($labels)
        );
    }

    /**
     * Words for a set of rooms: by name, or by tags.
     *
     * @param array<string, mixed>  $set
     * @param array<string, string> $roomNames
     */
    public static function set(array $set, array $roomNames): string
    {
        $rooms = array_values(array_filter((array) ($set['rooms'] ?? []), 'is_string'));
        if ($rooms !== []) {
            return self::join(array_map(static fn (string $r): string => self::room($r, $roomNames), $rooms));
        }
        $tags = array_values(array_filter((array) ($set['room_tags'] ?? []), 'is_string'));

        /* translators: %s: room tags */
        return $tags === [] ? __('no room', 'ritmo-revenue') : sprintf(__('the rooms tagged %s', 'ritmo-revenue'), self::join($tags));
    }

    /**
     * @param array<string, mixed>  $p
     * @param array<string, string> $roomNames
     */
    private static function atLeast(string $target, array $p, array $roomNames, string $currency): string
    {
        $reference = self::room((string) ($p['reference'] ?? ''), $roomNames);
        $margin = (array) ($p['margin'] ?? []);
        $value = (float) ($margin['value'] ?? 0);
        $amount = ($margin['type'] ?? 'percent') === 'amount' ? Money::format(abs($value), $currency) : self::number(abs($value)) . '%';
        $sentence = match (true) {
            /* translators: 1: room, 2: reference room */
            $value == 0.0 => sprintf(__('%1$s never costs less than %2$s.', 'ritmo-revenue'), $target, $reference),
            /* translators: 1: room, 2: reference room, 3: amount or percent */
            $value > 0 => sprintf(__('%1$s never costs less than %2$s plus %3$s.', 'ritmo-revenue'), $target, $reference, $amount),
            /* translators: 1: room, 2: reference room, 3: amount or percent */
            default => sprintf(__('%1$s never costs less than %2$s minus %3$s.', 'ritmo-revenue'), $target, $reference, $amount),
        };
        $units = (int) ($p['reference_min_units_left'] ?? 1);

        return $units < 2 ? $sentence : $sentence . ' ' . sprintf(
            /* translators: 1: reference room, 2: number of units */
            _n('This only holds while %1$s has at least %2$d unit free.', 'This only holds while %1$s has at least %2$d units free.', $units, 'ritmo-revenue'),
            $reference,
            $units
        );
    }

    /**
     * @param array<string, mixed>  $p
     * @param array<string, string> $roomNames
     */
    private static function between(string $target, array $p, array $roomNames): string
    {
        if (($p['mode'] ?? 'path') === 'percent_of') {
            return sprintf(
                /* translators: 1: room, 2: lowest percent, 3: highest percent, 4: reference room */
                __('%1$s stays between %2$s%% and %3$s%% of %4$s.', 'ritmo-revenue'),
                $target,
                self::number($p['min_percent'] ?? 0),
                self::number($p['max_percent'] ?? 0),
                self::room((string) ($p['reference'] ?? ''), $roomNames)
            );
        }

        return sprintf(
            /* translators: 1: room, 2: cheaper room, 3: dearer room, 4: lowest percent of the way, 5: highest percent of the way */
            __('%1$s stays between %2$s and %3$s: from the cheaper one up to %4$s–%5$s%% of the way.', 'ritmo-revenue'),
            $target,
            self::room((string) ($p['low_reference'] ?? ''), $roomNames),
            self::room((string) ($p['high_reference'] ?? ''), $roomNames),
            Percent::fromRatio((float) ($p['position_min'] ?? 0)),
            Percent::fromRatio((float) ($p['position_max'] ?? 1))
        );
    }

    /**
     * @param array<string, mixed>  $p
     * @param array<string, string> $roomNames
     */
    private static function premium(string $target, array $p, array $roomNames): string
    {
        $premium = (array) ($p['premium_percent'] ?? []);
        $parts = [
            /* translators: %s: percent */
            sprintf(__('%s%% on weekday nights', 'ritmo-revenue'), self::number($premium['weekday'] ?? 0)),
            /* translators: %s: percent */
            sprintf(__('%s%% on weekend nights', 'ritmo-revenue'), self::number($premium['weekend'] ?? 0)),
            /* translators: %s: percent */
            sprintf(__('%s%% on holiday nights', 'ritmo-revenue'), self::number($premium['holiday'] ?? 0)),
        ];

        /* translators: 1: room, 2: rooms, 3: premiums per kind of night */
        return sprintf(__('%1$s costs more than the dearest of %2$s: %3$s.', 'ritmo-revenue'), $target, self::set((array) ($p['set'] ?? []), $roomNames), self::join($parts));
    }

    /**
     * @param array<string, mixed>  $p
     * @param array<string, string> $roomNames
     */
    private static function occupancySwitch(string $target, array $p, array $roomNames): string
    {
        $trigger = (array) ($p['trigger'] ?? []);
        $threshold = self::number($trigger['threshold'] ?? 0);
        $when = ($trigger['unit'] ?? 'percent') === 'units'
            /* translators: 1: number of units, 2: rooms */
            ? sprintf(__('When %1$s units of %2$s are sold', 'ritmo-revenue'), $threshold, self::set((array) ($trigger['set'] ?? []), $roomNames))
            /* translators: 1: percent, 2: rooms */
            : sprintf(__('When %1$s%% of the units of %2$s are sold', 'ritmo-revenue'), $threshold, self::set((array) ($trigger['set'] ?? []), $roomNames));
        $then = (array) ($p['then'] ?? []);
        $groups = (array) ($then['by_night_group'] ?? []);
        $percentiles = array_values(array_unique(array_map(static fn (mixed $g): string => self::number(is_array($g) ? ($g['percentile'] ?? 0) : 0), $groups)));
        $action = ($then['kind'] ?? '') === 'hold' || $percentiles === []
            /* translators: %s: room */
            ? sprintf(__('%s keeps its price', 'ritmo-revenue'), $target)
            /* translators: 1: room, 2: market percentile */
            : sprintf(__('%1$s goes to the market percentile %2$s', 'ritmo-revenue'), $target, self::join($percentiles));
        $suspends = array_values(array_filter((array) ($p['suspends'] ?? []), 'is_string'));
        /* translators: %s: relation names */
        $stop = $suspends === [] ? '' : ' ' . sprintf(__('(and %s stop that night)', 'ritmo-revenue'), self::join($suspends));

        return $when . ', ' . $action . $stop . '.';
    }

    /**
     * @param array<string, string> $roomNames
     */
    private static function room(string $code, array $roomNames): string
    {
        return $roomNames[$code] ?? $code;
    }

    private static function number(mixed $value): string
    {
        return rtrim(rtrim(sprintf('%.2F', is_numeric($value) ? (float) $value : 0.0), '0'), '.');
    }

    /**
     * "A", "A and B", "A, B and C".
     *
     * @param list<string> $items
     */
    private static function join(array $items): string
    {
        if (count($items) < 2) {
            return $items[0] ?? '';
        }
        $last = array_pop($items);

        /* translators: 1: list of items, 2: last item */
        return sprintf(__('%1$s and %2$s', 'ritmo-revenue'), implode(', ', $items), $last);
    }
}
