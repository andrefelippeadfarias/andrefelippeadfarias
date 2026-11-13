<?php

declare(strict_types=1);

namespace RitmoFakeApis;

use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;

/**
 * The invented world both fake APIs describe: rooms, bookings, calendar rules and overrides
 * read from tests/fixtures/api/scenario.json, and everything derived from them (units sold,
 * availability, prices), so the PMS and the pricing system always tell the same story.
 */
final class Scenario
{
    /** Statuses that take a unit out of the PMS inventory; "black" is a blocked period. */
    private const TAKES_INVENTORY = ['confirmed', 'new', 'black'];

    /** Statuses the pricing system counts as sold. */
    private const SOLD = ['confirmed', 'new'];

    /**
     * @param array<string, mixed> $data
     */
    private function __construct(private readonly array $data)
    {
    }

    /**
     * @param string|null $start YYYY-MM-DD to move the whole scenario to (its first night); null keeps the file's dates
     */
    public static function load(string $file, ?string $start = null): self
    {
        $data = json_decode((string) file_get_contents($file), true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($data) || !isset($data['start'], $data['rooms'], $data['bookings'])) {
            throw new RuntimeException('The scenario file is not a scenario.');
        }
        if ($start === null || $start === '' || $start === $data['start']) {
            return new self($data);
        }

        $days = (int) self::day($data['start'])->diff(self::day($start))->format('%r%a');

        return new self(self::shifted($data, $days));
    }

    /**
     * The same world with what a write run stored: the date rules of each listing and the values
     * written to the PMS calendar (room id => night => values).
     *
     * @param array<string, list<array<string, mixed>>>             $overrides
     * @param array<string, array<string, array<string, mixed>>>    $calendar
     */
    public function withWrites(array $overrides, array $calendar): self
    {
        return new self(['overrides' => $overrides, 'calendar_writes' => $calendar] + $this->data);
    }

    /**
     * @return array<string, mixed>
     */
    public function beds24(): array
    {
        return $this->data['beds24'];
    }

    /**
     * @return array<string, mixed>
     */
    public function priceLabs(): array
    {
        return $this->data['pricelabs'];
    }

    public function currency(): string
    {
        return (string) $this->data['currency'];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function rooms(): array
    {
        return $this->data['rooms'];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function room(int $roomId): ?array
    {
        foreach ($this->rooms() as $room) {
            if ($room['room_id'] === $roomId) {
                return $room;
            }
        }

        return null;
    }

    /**
     * The pricing system names a listing of this PMS "<property id>___<room id>".
     *
     * @param array<string, mixed> $room
     */
    public function listingId(array $room): string
    {
        return $this->data['beds24']['property']['id'] . '___' . $room['room_id'];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function roomOfListing(string $listingId): ?array
    {
        foreach ($this->rooms() as $room) {
            if ($this->listingId($room) === $listingId) {
                return $room;
            }
        }

        return null;
    }

    /**
     * Bookings as the PMS sends them: the scenario's fields plus the property and the guest
     * fields of a real booking, filled with synthetic text marked GUESTDATA.
     *
     * @return list<array<string, mixed>>
     */
    public function bookings(): array
    {
        $propertyId = $this->data['beds24']['property']['id'];

        return array_map(
            static function (array $booking) use ($propertyId): array {
                $tag = 'GUESTDATA-' . $booking['id'];

                return ['propertyId' => $propertyId, 'unitId' => 1, 'subStatus' => '', 'numAdult' => 2, 'numChild' => 0, 'deposit' => 0, 'tax' => 0, 'commission' => 0]
                    + $booking
                    + [
                        'title' => '',
                        'firstName' => $tag . '-first',
                        'lastName' => $tag . '-last',
                        'email' => strtolower($tag) . '@example.invalid',
                        'phone' => '+00 000 ' . $tag,
                        'mobile' => '',
                        'address' => $tag . ' street',
                        'city' => $tag . ' city',
                        'comments' => $tag . ' comment',
                        'notes' => $tag . ' note',
                        'message' => $tag . ' message',
                        'custom1' => $tag . ' custom',
                        'reference' => $tag . ' reference',
                        'rateDescription' => 'Flexible rate',
                        'apiReference' => 'sim-' . $booking['id'],
                        'lang' => 'en',
                        'guests' => [['firstName' => $tag . '-first', 'lastName' => $tag . '-last']],
                        'infoItems' => [['code' => 'NOTE', 'text' => $tag . ' info']],
                    ];
            },
            $this->data['bookings']
        );
    }

    /**
     * Units of a room free in the PMS on a night; negative when overbooked.
     *
     * @param array<string, mixed> $room
     */
    public function available(array $room, string $night): int
    {
        $written = $this->data['calendar_writes'][(string) $room['room_id']][$night]['numAvail'] ?? null;

        return ($written ?? $room['units']) - $this->unitsTaken($room['room_id'], $night, self::TAKES_INVENTORY);
    }

    /**
     * Units of a room the pricing system counts as sold on a night.
     *
     * @param array<string, mixed> $room
     */
    public function sold(array $room, string $night): int
    {
        return $this->unitsTaken($room['room_id'], $night, self::SOLD);
    }

    /**
     * The PMS calendar values of a room night: the room's defaults changed by the calendar rules.
     *
     * @param array<string, mixed> $room
     *
     * @return array{minStay: int, maxStay: int, override: string}
     */
    public function calendarDay(array $room, string $night): array
    {
        $day = ['minStay' => $room['min_stay'], 'maxStay' => $room['max_stay'], 'override' => 'none'];
        foreach ($this->data['calendar_rules'] ?? [] as $rule) {
            if ($rule['room_id'] !== $room['room_id'] || $night < $rule['from'] || $night > $rule['to']) {
                continue;
            }
            $day['minStay'] = $rule['min_stay'] ?? $day['minStay'];
            $day['maxStay'] = $rule['max_stay'] ?? $day['maxStay'];
            $day['override'] = $rule['override'] ?? $day['override'];
        }
        $written = $this->data['calendar_writes'][(string) $room['room_id']][$night] ?? [];

        return array_intersect_key($written, $day) + $day;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function overrides(string $listingId): array
    {
        return $this->data['overrides'][$listingId] ?? [];
    }

    /**
     * The price of the pricing system's algorithm for a room night: the weekend or weekday
     * rate (the nights of Friday and Saturday are weekend).
     *
     * @param array<string, mixed> $room
     */
    public function uncustomizedPrice(array $room, string $night): float
    {
        $weekend = in_array(self::day($night)->format('N'), ['5', '6'], true);

        return (float) ($weekend ? $room['weekend_price'] : $room['weekday_price']);
    }

    /**
     * The final price of a room night: the algorithm's price, a little higher for each unit
     * sold, then the date override in force (a price replaces it; a floor and a ceiling bound it).
     *
     * @param array<string, mixed> $room
     */
    public function price(array $room, string $night): float
    {
        $price = $this->uncustomizedPrice($room, $night) + 10.0 * $this->sold($room, $night);
        $base = (float) $room['base'];

        foreach ($this->overrides($this->listingId($room)) as $override) {
            if ($override['date'] !== $night) {
                continue;
            }
            if (isset($override['price'], $override['price_type'])) {
                $price = $override['price_type'] === 'fixed' ? (float) $override['price'] : $base * (1 + (float) $override['price'] / 100);
            }
            $floor = self::bound($override, 'min_price', $base);
            $ceiling = self::bound($override, 'max_price', $base);
            $price = $ceiling === null ? $price : min($price, $ceiling);
            $price = $floor === null ? $price : max($price, $floor);
        }

        return round($price, 2);
    }

    /**
     * @param array<string, mixed> $override
     */
    private static function bound(array $override, string $field, float $base): ?float
    {
        if (!isset($override[$field])) {
            return null;
        }

        return ($override[$field . '_type'] ?? 'percent_base') === 'fixed'
            ? (float) $override[$field]
            : $base * (1 + (float) $override[$field] / 100);
    }

    /**
     * @param list<string> $statuses
     */
    private function unitsTaken(int $roomId, string $night, array $statuses): int
    {
        $units = 0;
        foreach ($this->data['bookings'] as $booking) {
            if ($booking['roomId'] === $roomId
                && in_array($booking['status'], $statuses, true)
                && $booking['arrival'] <= $night
                && $night < $booking['departure']
            ) {
                $units += $booking['roomQty'];
            }
        }

        return $units;
    }

    private static function day(string $ymd): DateTimeImmutable
    {
        return new DateTimeImmutable($ymd . 'T00:00:00', new DateTimeZone('UTC'));
    }

    /**
     * The value with every date (YYYY-MM-DD) and instant (YYYY-MM-DDTHH:MM:SSZ) moved by a
     * number of days.
     */
    private static function shifted(mixed $value, int $days): mixed
    {
        if (is_array($value)) {
            return array_map(static fn (mixed $item): mixed => self::shifted($item, $days), $value);
        }
        if (!is_string($value) || preg_match('/^(\d{4}-\d{2}-\d{2})(T\d{2}:\d{2}:\d{2}Z)?$/', $value, $match) !== 1) {
            return $value;
        }

        return self::day($match[1])->modify(sprintf('%+d days', $days))->format('Y-m-d') . ($match[2] ?? '');
    }
}
