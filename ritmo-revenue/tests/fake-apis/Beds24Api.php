<?php

declare(strict_types=1);

namespace RitmoFakeApis;

use DateTimeImmutable;
use DateTimeZone;

/**
 * The read side of the Beds24 API V2 in its real response format: the token exchange,
 * /properties, /bookings (filters and pages) and /inventory/rooms/calendar (ranges of equal
 * days). Anything that is not a GET is refused with 405 while the writable world is off; with it
 * on, an authorised write goes to Beds24Writes.
 */
final class Beds24Api
{
    public function __construct(
        private readonly Scenario $scenario,
        private readonly Journal $journal,
        private readonly ?WriteWorld $world = null,
    ) {
    }

    /**
     * @param array<string, list<string>> $query
     * @param array<string, string>       $headers request headers, names in lowercase
     */
    public function handle(string $method, string $path, array $query, array $headers, string $body = ''): Reply
    {
        $reply = $this->route($method, $path, $query, $headers, $body);

        return $reply->withHeaders($this->creditHeaders());
    }

    /**
     * @param array<string, list<string>> $query
     * @param array<string, string>       $headers
     */
    private function route(string $method, string $path, array $query, array $headers, string $body): Reply
    {
        if ($method !== 'GET') {
            if ($this->world === null || !$this->world->enabled()) {
                return self::error(405, 'The simulator is read-only.');
            }

            return $this->authorised($headers) ? (new Beds24Writes($this->scenario, $this->world))->handle($method, $path, $body) : self::error(401, 'Token is missing or not valid.');
        }
        if ($path === '/authentication/token') {
            return $this->token($headers);
        }
        if (!$this->authorised($headers)) {
            return self::error(401, 'Token is missing or not valid.');
        }

        return match ($path) {
            '/properties' => $this->listing('property', [$this->scenario->beds24()['property'] + ['currency' => $this->scenario->currency()]]),
            '/bookings' => $this->bookings($query),
            '/inventory/rooms/calendar' => $this->calendar($query),
            default => self::error(404, 'Not found.'),
        };
    }

    /**
     * @param array<string, string> $headers
     */
    private function token(array $headers): Reply
    {
        $settings = $this->scenario->beds24();
        if (($headers['refreshtoken'] ?? '') !== $settings['refresh_token']) {
            return self::error(401, 'Refresh token is not valid.');
        }

        return Reply::json(200, ['token' => $this->journal->issueAccessToken(), 'expiresIn' => $settings['access_token_lifetime']]);
    }

    /**
     * @param array<string, string> $headers
     */
    private function authorised(array $headers): bool
    {
        $token = $headers['token'] ?? '';

        return $token !== '' && ($token === $this->scenario->beds24()['long_life_token'] || $this->journal->isAccessToken($token));
    }

    /**
     * @param array<string, list<string>> $query
     */
    private function bookings(array $query): Reply
    {
        $one = static fn (string $name): ?string => $query[$name][0] ?? null;
        $modifiedFrom = null;
        if ($one('modifiedFrom') !== null) {
            if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', (string) $one('modifiedFrom')) !== 1) {
                return self::error(400, 'modifiedFrom is not an instant.');
            }
            $modifiedFrom = self::instant((string) $one('modifiedFrom'));
        }

        $found = array_values(array_filter(
            $this->scenario->bookings(),
            static function (array $booking) use ($query, $one, $modifiedFrom): bool {
                return (!isset($query['status']) || in_array($booking['status'], $query['status'], true))
                    && (!isset($query['propertyId']) || in_array((string) $booking['propertyId'], $query['propertyId'], true))
                    && (!isset($query['roomId']) || in_array((string) $booking['roomId'], $query['roomId'], true))
                    && ($one('arrivalTo') === null || $booking['arrival'] <= $one('arrivalTo'))
                    && ($one('departureFrom') === null || $booking['departure'] >= $one('departureFrom'))
                    && ($modifiedFrom === null || self::instant($booking['modifiedTime']) >= $modifiedFrom);
            }
        ));
        if ($one('includeInvoiceItems') !== 'true') {
            $found = array_map(static fn (array $booking): array => array_diff_key($booking, ['invoiceItems' => true]), $found);
        }

        $perPage = (int) $this->scenario->beds24()['bookings_per_page'];
        $page = max(1, (int) ($one('page') ?? 1));
        $offset = ($page - 1) * $perPage;

        return $this->listing('booking', array_slice($found, $offset, $perPage), count($found) > $offset + $perPage);
    }

    /**
     * @param array<string, list<string>> $query
     */
    private function calendar(array $query): Reply
    {
        $from = $query['startDate'][0] ?? '';
        $to = $query['endDate'][0] ?? '';
        if (!self::isDay($from) || !self::isDay($to) || $to < $from || !isset($query['roomId'])) {
            return self::error(400, 'roomId, startDate and endDate are required.');
        }

        $wanted = array_filter([
            'numAvail' => ($query['includeNumAvail'][0] ?? '') === 'true',
            'minStay' => ($query['includeMinStay'][0] ?? '') === 'true',
            'maxStay' => ($query['includeMaxStay'][0] ?? '') === 'true',
            'override' => ($query['includeOverride'][0] ?? '') === 'true',
            'price1' => ($query['includePrices'][0] ?? '') === 'true',
        ]);

        $rooms = [];
        foreach ($query['roomId'] as $roomId) {
            $room = $this->scenario->room((int) $roomId);
            if ($room === null) {
                continue;
            }
            $rooms[] = [
                'roomId' => $room['room_id'],
                'propertyId' => $this->scenario->beds24()['property']['id'],
                'name' => $room['name'],
                'calendar' => $this->ranges($room, $from, $to, array_keys($wanted)),
            ];
        }

        return $this->listing('calendar', $rooms);
    }

    /**
     * The calendar of a room as the API sends it: consecutive days with the same values are
     * one range.
     *
     * @param array<string, mixed> $room
     * @param list<string>         $fields
     *
     * @return list<array<string, mixed>>
     */
    private function ranges(array $room, string $from, string $to, array $fields): array
    {
        $ranges = [];
        $utc = new DateTimeZone('UTC');
        for ($day = new DateTimeImmutable($from, $utc); $day->format('Y-m-d') <= $to; $day = $day->modify('+1 day')) {
            $night = $day->format('Y-m-d');
            $values = array_intersect_key(
                ['numAvail' => $this->scenario->available($room, $night), 'price1' => $this->scenario->price($room, $night)]
                    + $this->scenario->calendarDay($room, $night),
                array_flip($fields)
            );

            $last = count($ranges) - 1;
            if ($last >= 0 && array_diff_key($ranges[$last], ['from' => true, 'to' => true]) == $values) {
                $ranges[$last]['to'] = $night;
            } else {
                $ranges[] = ['from' => $night, 'to' => $night] + $values;
            }
        }

        return $ranges;
    }

    /**
     * @param list<array<string, mixed>> $data
     */
    private function listing(string $type, array $data, bool $nextPage = false): Reply
    {
        return Reply::json(200, [
            'success' => true,
            'type' => $type,
            'count' => count($data),
            'pages' => ['nextPageExists' => $nextPage, 'nextPageLink' => null],
            'data' => $data,
        ]);
    }

    /**
     * @return array<string, string>
     */
    private function creditHeaders(): array
    {
        $credit = $this->scenario->beds24()['credit'];

        return [
            'X-Request-Cost' => (string) $credit['cost'],
            'X-Five-Min-Limit-Remaining' => (string) $credit['remaining'],
            'X-Five-Min-Limit-Resets-In' => (string) $credit['resets_in'],
        ];
    }

    private static function error(int $status, string $message): Reply
    {
        return Reply::json($status, ['success' => false, 'type' => 'error', 'code' => $status, 'error' => $message]);
    }

    private static function isDay(string $text): bool
    {
        $day = DateTimeImmutable::createFromFormat('!Y-m-d', $text, new DateTimeZone('UTC'));

        return $day !== false && $day->format('Y-m-d') === $text;
    }

    private static function instant(string $text): int
    {
        return (new DateTimeImmutable($text, new DateTimeZone('UTC')))->getTimestamp();
    }
}
