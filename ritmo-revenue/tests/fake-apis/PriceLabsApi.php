<?php

declare(strict_types=1);

namespace RitmoFakeApis;

use DateTimeImmutable;
use DateTimeZone;

/**
 * The read side of the PriceLabs Customer API in its real response format: GET /listings,
 * POST /listing_prices (a read that takes its parameters in the body) and
 * GET /listings/{id}/overrides. Every other request that is not a GET is refused with 405.
 */
final class PriceLabsApi
{
    private const MAX_NIGHTS = 500;

    public function __construct(private readonly Scenario $scenario)
    {
    }

    /**
     * @param array<string, list<string>> $query
     * @param array<string, string>       $headers request headers, names in lowercase
     */
    public function handle(string $method, string $path, array $query, array $headers, string $body): Reply
    {
        $key = $headers['x-api-key'] ?? '';
        if ($key === '') {
            return self::error(403, 'API_KEY_MISSING');
        }
        if ($key !== $this->scenario->priceLabs()['api_key']) {
            return self::error(403, 'API_KEY_INVALID');
        }

        if ($method === 'POST' && $path === '/listing_prices') {
            return $this->prices($body);
        }
        if ($method !== 'GET') {
            return self::error(405, 'READ_ONLY_SIMULATOR');
        }
        if ($path === '/listings') {
            return Reply::json(200, ['listings' => $this->listings()]);
        }
        if (preg_match('#^/listings/([^/]+)/overrides$#', $path, $match) === 1) {
            return $this->overrides(rawurldecode($match[1]), $query);
        }

        return self::error(404, 'NOT_FOUND');
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function listings(): array
    {
        $settings = $this->scenario->priceLabs();
        $listings = [];
        foreach ($this->scenario->rooms() as $room) {
            $listings[] = [
                'id' => $this->scenario->listingId($room),
                'pms' => $settings['pms'],
                'name' => $room['name'],
                'min' => $room['min'],
                'base' => $room['base'],
                'max' => $room['max'],
                'currency' => $this->scenario->currency(),
                'push_enabled' => $room['push_enabled'],
                'last_date_pushed' => $room['push_enabled'] ? $settings['last_date_pushed'] : null,
                'last_refreshed_at' => $settings['last_refreshed_at'],
            ];
        }

        return array_merge($listings, $settings['other_listings'] ?? []);
    }

    private function prices(string $body): Reply
    {
        $request = json_decode($body, true);
        if (!is_array($request) || !is_array($request['listings'] ?? null)) {
            return self::error(400, 'INVALID_BODY');
        }

        $calendars = [];
        foreach ($request['listings'] as $wanted) {
            $id = (string) ($wanted['id'] ?? '');
            $pms = (string) ($wanted['pms'] ?? '');
            $room = $pms === $this->scenario->priceLabs()['pms'] ? $this->scenario->roomOfListing($id) : null;
            $nights = $room === null ? null : self::nights((string) ($wanted['dateFrom'] ?? ''), (string) ($wanted['dateTo'] ?? ''));
            if ($room === null || $nights === null) {
                $calendars[] = ['id' => $id, 'pms' => $pms, 'error_status' => $room === null ? 'LISTING_NOT_PRESENT' : 'LISTING_NO_DATA'];
                continue;
            }

            $calendars[] = [
                'id' => $id,
                'pms' => $pms,
                'currency' => $this->scenario->currency(),
                'last_refreshed_at' => $this->scenario->priceLabs()['last_refreshed_at'],
                'data' => array_map(fn (string $night): array => $this->night($room, $night), $nights),
            ];
        }

        return Reply::json(200, $calendars);
    }

    /**
     * One night of a listing's calendar. A listing with one unit has no "sold/total" text and
     * reports -1 as its user price when it is booked.
     *
     * @param array<string, mixed> $room
     *
     * @return array<string, mixed>
     */
    private function night(array $room, string $night): array
    {
        $units = (int) $room['units'];
        $sold = min($this->scenario->sold($room, $night), $units);
        $price = $this->scenario->price($room, $night);
        $day = $this->scenario->calendarDay($room, $night);

        $minStay = $day['minStay'];
        foreach ($this->scenario->overrides($this->scenario->listingId($room)) as $override) {
            if ($override['date'] === $night && isset($override['min_stay'])) {
                $minStay = $override['min_stay'];
            }
        }

        $row = [
            'date' => $night,
            'price' => $price,
            'user_price' => $units === 1 && $sold > 0 ? -1 : $price,
            'uncustomized_price' => $this->scenario->uncustomizedPrice($room, $night),
            'min_stay' => $minStay,
            'booking_status' => $sold >= $units ? 'Booked' : '',
            'unbookable' => $day['override'] === 'blackout' ? 1 : 0,
        ];
        if ($units > 1) {
            $row['multi_unit_occupancy'] = $sold . '/' . $units;
        }

        return $row;
    }

    /**
     * @param array<string, list<string>> $query
     */
    private function overrides(string $listingId, array $query): Reply
    {
        if (($query['pms'][0] ?? '') !== $this->scenario->priceLabs()['pms'] || $this->scenario->roomOfListing($listingId) === null) {
            return self::error(400, 'LISTING_NOT_PRESENT');
        }

        $from = $query['start_date'][0] ?? '0000-00-00';
        $to = $query['end_date'][0] ?? '9999-12-31';

        return Reply::json(200, ['overrides' => array_values(array_filter(
            $this->scenario->overrides($listingId),
            static fn (array $override): bool => $override['date'] >= $from && $override['date'] <= $to
        ))]);
    }

    /**
     * The nights from one day to another, both included; null when they are not a valid span.
     *
     * @return list<string>|null
     */
    private static function nights(string $from, string $to): ?array
    {
        $utc = new DateTimeZone('UTC');
        $first = DateTimeImmutable::createFromFormat('!Y-m-d', $from, $utc);
        $last = DateTimeImmutable::createFromFormat('!Y-m-d', $to, $utc);
        if ($first === false || $last === false || $last < $first) {
            return null;
        }

        $nights = [];
        for ($day = $first; $day <= $last && count($nights) < self::MAX_NIGHTS; $day = $day->modify('+1 day')) {
            $nights[] = $day->format('Y-m-d');
        }

        return $nights;
    }

    private static function error(int $status, string $code): Reply
    {
        return Reply::json($status, ['error' => ['code' => $code, 'message' => 'Simulated PriceLabs error.']]);
    }
}
