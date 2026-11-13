<?php

declare(strict_types=1);

namespace Ritmo\Connectors\PriceLabs;

use DateTimeImmutable;
use DateTimeZone;
use Ritmo\Contracts\Exception\WriteBlocked;

/**
 * The bodies of the two date-rule calls of the pricing system, built from a whitelist of fields:
 * floor and ceiling only as whole percentages of the listing base ("percent_base", -75 to 1000),
 * the minimum stay (1 to 30), both arrival/departure masks with their switch, a reason and the
 * currency. Never a price, never a fixed amount, never an expiry ("lead_time_expiry": a protection
 * rule never expires), never the same date twice in one call (the last one would win).
 * Anything else stops the call before it is sent (WriteBlocked: never retried).
 * Reference: beds24-free part of validar_plano.montar_payload; apis-verificadas.md (overrides).
 */
final class OverrideWriteBody
{
    public const FIELDS = ['date', 'reason', 'min_price', 'min_price_type', 'max_price', 'max_price_type', 'currency', 'min_stay', 'check_in_check_out_enabled', 'check_in', 'check_out'];

    private const PERCENT_TYPE = 'percent_base';
    private const MIN_PERCENT = -75;
    private const MAX_PERCENT = 1000;
    private const MAX_STAY = 30;
    private const REASON_LENGTH = 500;
    private const CHANGES = ['min_price', 'max_price', 'min_stay', 'check_in_check_out_enabled'];

    /**
     * @param list<array<string, mixed>> $overrides
     *
     * @return array{pms: string, update_children: false, overrides: list<array<string, string>>}
     *
     * @throws WriteBlocked on a field outside the whitelist or a value out of range
     */
    public static function write(string $pms, array $overrides): array
    {
        if ($overrides === []) {
            throw self::refused('a write carries at least one date rule');
        }
        $clean = [];
        $seen = [];
        foreach ($overrides as $override) {
            $row = self::override($override);
            if (isset($seen[$row['date']])) {
                throw self::refused('a call never repeats a date');
            }
            $seen[$row['date']] = true;
            $clean[] = $row;
        }

        return ['pms' => $pms, 'update_children' => false, 'overrides' => $clean];
    }

    /**
     * @param list<string> $dates YYYY-MM-DD
     *
     * @return array{pms: string, update_children: false, overrides: list<array{date: string}>}
     *
     * @throws WriteBlocked on no date, a malformed date or a repeated one
     */
    public static function delete(string $pms, array $dates): array
    {
        if ($dates === [] || count(array_unique($dates)) !== count($dates)) {
            throw self::refused('a deletion names each date once');
        }

        return ['pms' => $pms, 'update_children' => false, 'overrides' => array_map(static fn (mixed $date): array => ['date' => self::date($date)], array_values($dates))];
    }

    /**
     * @param array{overrides: list<array<string, string>>} $body
     *
     * @return list<string>
     */
    public static function dates(array $body): array
    {
        return array_column($body['overrides'], 'date');
    }

    /**
     * Names of the fields the body changes (the date left out).
     *
     * @param array{overrides: list<array<string, string>>} $body
     *
     * @return list<string>
     */
    public static function fields(array $body): array
    {
        $fields = [];
        foreach ($body['overrides'] as $override) {
            foreach (array_keys($override) as $field) {
                $fields[$field] = true;
            }
        }
        unset($fields['date']);

        return array_keys($fields);
    }

    /**
     * @return array<string, string>
     *
     * @throws WriteBlocked
     */
    private static function override(mixed $override): array
    {
        if (!is_array($override)) {
            throw self::refused('a date rule is an object');
        }
        $unknown = array_diff(array_map('strval', array_keys($override)), self::FIELDS);
        if ($unknown !== []) {
            throw self::refused(sprintf('field "%s" is never written', reset($unknown)));
        }
        if (array_intersect(array_keys($override), self::CHANGES) === []) {
            throw self::refused('a date rule changes a floor, a ceiling, a stay or the arrival and departure days');
        }
        $row = ['date' => self::date($override['date'] ?? null)];
        $row += self::bound($override, 'min_price');
        $row += self::bound($override, 'max_price');
        if (isset($row['min_price'], $row['max_price']) && (int) $row['min_price'] > (int) $row['max_price']) {
            throw self::refused('the floor is above the ceiling');
        }
        if (array_key_exists('currency', $override)) {
            $row['currency'] = self::currency($override['currency'], $row);
        }
        if (array_key_exists('min_stay', $override)) {
            $row['min_stay'] = (string) self::integer($override['min_stay'], 1, self::MAX_STAY, 'minimum stay');
        }
        $row += self::days($override);
        if (array_key_exists('reason', $override)) {
            $row['reason'] = self::reason($override['reason']);
        }

        return $row;
    }

    /**
     * @param array<array-key, mixed> $override
     *
     * @return array<string, string>
     */
    private static function bound(array $override, string $field): array
    {
        $type = $override[$field . '_type'] ?? null;
        if (!array_key_exists($field, $override)) {
            if ($type !== null) {
                throw self::refused(sprintf('"%s_type" without "%s"', $field, $field));
            }

            return [];
        }
        if ($type !== self::PERCENT_TYPE) {
            throw self::refused(sprintf('"%s" goes only as a whole percentage of the base', $field));
        }

        return [$field => (string) self::integer($override[$field], self::MIN_PERCENT, self::MAX_PERCENT, $field), $field . '_type' => self::PERCENT_TYPE];
    }

    /**
     * @param array<array-key, mixed> $override
     *
     * @return array<string, string>
     */
    private static function days(array $override): array
    {
        $parts = ['check_in_check_out_enabled', 'check_in', 'check_out'];
        $given = array_intersect($parts, array_keys($override));
        if ($given === []) {
            return [];
        }
        if (count($given) !== 3 || (string) $override['check_in_check_out_enabled'] !== '1') {
            throw self::refused('arrival and departure days go together, both masks with the switch on');
        }
        foreach (['check_in', 'check_out'] as $mask) {
            if (!is_string($override[$mask]) || preg_match('/^[01]{7}$/', $override[$mask]) !== 1) {
                throw self::refused('a weekday mask has seven characters, each 0 or 1');
            }
        }

        return ['check_in_check_out_enabled' => '1', 'check_in' => $override['check_in'], 'check_out' => $override['check_out']];
    }

    /**
     * @param array<string, string> $row
     */
    private static function currency(mixed $currency, array $row): string
    {
        if (!is_string($currency) || preg_match('/^[A-Z]{3}$/', $currency) !== 1 || !isset($row['min_price']) && !isset($row['max_price'])) {
            throw self::refused('the currency goes with a floor or a ceiling, as three capital letters');
        }

        return $currency;
    }

    private static function reason(mixed $reason): string
    {
        if (!is_string($reason) || mb_strlen($reason, 'UTF-8') > self::REASON_LENGTH || preg_match('/[\x00-\x1f\x7f]/', $reason) === 1) {
            throw self::refused('the reason is one line of text');
        }

        return $reason;
    }

    private static function integer(mixed $value, int $min, int $max, string $what): int
    {
        $text = is_int($value) ? (string) $value : $value;
        if (!is_string($text) || preg_match('/^-?\d{1,4}$/', $text) !== 1 || (int) $text < $min || (int) $text > $max) {
            throw self::refused(sprintf('the %s must be a whole number from %d to %d', str_replace('_', ' ', $what), $min, $max));
        }

        return (int) $text;
    }

    private static function date(mixed $date): string
    {
        $parsed = is_string($date) ? DateTimeImmutable::createFromFormat('!Y-m-d', $date, new DateTimeZone('UTC')) : false;
        if ($parsed === false || $parsed->format('Y-m-d') !== $date) {
            throw self::refused('a date rule needs a valid YYYY-MM-DD date');
        }

        return $date;
    }

    private static function refused(string $why): WriteBlocked
    {
        return WriteBlocked::forRequest('POST', 'pricing date rules', $why);
    }
}
