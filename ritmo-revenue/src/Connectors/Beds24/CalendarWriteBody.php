<?php

declare(strict_types=1);

namespace Ritmo\Connectors\Beds24;

use DateTimeImmutable;
use DateTimeZone;
use Ritmo\Contracts\Exception\WriteBlocked;

/**
 * The body of POST /inventory/rooms/calendar, built from a whitelist: per range of nights of a
 * room, only the availability (0 to the units of the room), the minimum and maximum stay (1 to
 * 30, minimum not above maximum), the arrival/departure restriction (none, blackout, noCheckIn,
 * noCheckOut, noCheckInOrCheckOut) and the per-channel booking limit (booking, expedia, airbnb;
 * 0 to the units, sent as text because the API ignores a numeric 0; null lifts the limit).
 * Never a price (no key starting with "price") and nothing about bookings. Anything else stops
 * the call before it is sent (WriteBlocked). Ranges are grouped by room in their order.
 * Reference: beds24.corpo_edicao and the blocks of beds24.editar_lote.
 */
final class CalendarWriteBody
{
    public const MAX_RANGES = 100;

    public const RESTRICTIONS = ['none', 'blackout', 'noCheckIn', 'noCheckOut', 'noCheckInOrCheckOut'];

    public const CHANNELS = ['booking', 'expedia', 'airbnb'];

    private const KEYS = ['roomId', 'from', 'to', 'numAvail', 'minStay', 'maxStay', 'override', 'channels'];

    private const MAX_STAY = 30;

    /**
     * @param list<array<string, mixed>> $ranges   ranges in the API's fields: roomId, from, to and the values to set
     * @param array<string, int>         $units    Beds24 room id => units of the room
     *
     * @return list<array{roomId: int, calendar: list<array<string, mixed>>}>
     *
     * @throws WriteBlocked on a forbidden field, a value out of range or more ranges than one call carries
     */
    public static function build(array $ranges, array $units): array
    {
        if ($ranges === [] || count($ranges) > self::MAX_RANGES) {
            throw self::refused(sprintf('a calendar call carries from 1 to %d ranges', self::MAX_RANGES));
        }
        $byRoom = [];
        foreach ($ranges as $range) {
            [$roomId, $span] = self::range($range, $units);
            $byRoom[$roomId][] = $span;
        }
        $body = [];
        foreach ($byRoom as $roomId => $calendar) {
            $body[] = ['roomId' => (int) $roomId, 'calendar' => $calendar];
        }

        return $body;
    }

    /**
     * Room id => nights (YYYY-MM-DD) the body touches.
     *
     * @param list<array{roomId: int, calendar: list<array<string, mixed>>}> $body
     *
     * @return array<string, list<string>>
     */
    public static function nights(array $body): array
    {
        $nights = [];
        foreach ($body as $room) {
            foreach ($room['calendar'] as $span) {
                $day = self::day($span['from']);
                for ($last = self::day($span['to']); $day <= $last; $day = $day->modify('+1 day')) {
                    $nights[(string) $room['roomId']][] = $day->format('Y-m-d');
                }
            }
        }

        return $nights;
    }

    /**
     * Names of the fields the body sets ("channels.booking" for a channel limit).
     *
     * @param list<array{roomId: int, calendar: list<array<string, mixed>>}> $body
     *
     * @return list<string>
     */
    public static function fields(array $body): array
    {
        $fields = [];
        foreach ($body as $room) {
            foreach ($room['calendar'] as $span) {
                foreach ($span as $field => $value) {
                    if ($field === 'channels') {
                        foreach (array_keys($value) as $channel) {
                            $fields['channels.' . $channel] = true;
                        }
                    } elseif ($field !== 'from' && $field !== 'to') {
                        $fields[$field] = true;
                    }
                }
            }
        }

        return array_keys($fields);
    }

    /**
     * @param array<string, int> $units
     *
     * @return array{0: string, 1: array<string, mixed>}
     */
    private static function range(mixed $range, array $units): array
    {
        if (!is_array($range)) {
            throw self::refused('a range is an object');
        }
        foreach (array_keys($range) as $key) {
            if (str_starts_with(strtolower((string) $key), 'price')) {
                throw self::refused('a price is never written to the PMS');
            }
            if (!in_array($key, self::KEYS, true)) {
                throw self::refused(sprintf('field "%s" is never written to the PMS calendar', $key));
            }
        }
        $roomId = is_int($range['roomId'] ?? null) ? (string) $range['roomId'] : ($range['roomId'] ?? '');
        if (!is_string($roomId) || preg_match('/^[1-9]\d{0,17}$/', $roomId) !== 1) {
            throw self::refused('a range names its room by a Beds24 room id');
        }
        $from = self::date($range['from'] ?? null);
        $to = self::date($range['to'] ?? null);
        if ($to < $from) {
            throw self::refused('a range ends before it starts');
        }
        $span = ['from' => $from, 'to' => $to] + self::values($range, $units[$roomId] ?? null);
        if (count($span) === 2) {
            throw self::refused('a range changes at least one field');
        }

        return [$roomId, $span];
    }

    /**
     * @param array<array-key, mixed> $range
     *
     * @return array<string, mixed>
     */
    private static function values(array $range, ?int $units): array
    {
        $values = [];
        foreach (['minStay', 'maxStay'] as $stay) {
            if (array_key_exists($stay, $range)) {
                $values[$stay] = self::integer($range[$stay], 1, self::MAX_STAY, $stay === 'minStay' ? 'minimum stay' : 'maximum stay');
            }
        }
        if (isset($values['minStay'], $values['maxStay']) && $values['minStay'] > $values['maxStay']) {
            throw self::refused('the minimum stay is above the maximum: the night would not sell');
        }
        if (array_key_exists('override', $range)) {
            if (!in_array($range['override'], self::RESTRICTIONS, true)) {
                throw self::refused('unknown arrival/departure restriction');
            }
            $values['override'] = $range['override'];
        }
        if (array_key_exists('numAvail', $range)) {
            $values['numAvail'] = self::integer($range['numAvail'], 0, self::units($units), 'availability');
        }
        if (array_key_exists('channels', $range)) {
            $values['channels'] = self::channels($range['channels'], $units);
        }

        return $values;
    }

    /**
     * @return array<string, array{maxBookings: string|null}>
     */
    private static function channels(mixed $channels, ?int $units): array
    {
        if (!is_array($channels) || $channels === []) {
            throw self::refused('channel limits are an object of channel => limit');
        }
        $out = [];
        foreach ($channels as $channel => $limit) {
            if (!in_array($channel, self::CHANNELS, true)) {
                throw self::refused('unknown channel');
            }
            $out[$channel] = ['maxBookings' => $limit === null ? null : (string) self::integer($limit, 0, self::units($units), 'channel limit')];
        }

        return $out;
    }

    private static function units(?int $units): int
    {
        return $units ?? throw self::refused('the units of the room are unknown, so its availability is not written');
    }

    private static function integer(mixed $value, int $min, int $max, string $what): int
    {
        if (!is_int($value) || $value < $min || $value > $max) {
            throw self::refused(sprintf('the %s must be a whole number from %d to %d', $what, $min, $max));
        }

        return $value;
    }

    private static function date(mixed $date): string
    {
        $parsed = is_string($date) ? DateTimeImmutable::createFromFormat('!Y-m-d', $date, new DateTimeZone('UTC')) : false;
        if ($parsed === false || $parsed->format('Y-m-d') !== $date) {
            throw self::refused('a range needs valid YYYY-MM-DD dates');
        }

        return $date;
    }

    private static function day(string $date): DateTimeImmutable
    {
        return new DateTimeImmutable($date, new DateTimeZone('UTC'));
    }

    private static function refused(string $why): WriteBlocked
    {
        return WriteBlocked::forRequest('POST', '/inventory/rooms/calendar', $why);
    }
}
