<?php

declare(strict_types=1);

namespace Ritmo\Connectors\Beds24;

use DateTimeImmutable;
use DateTimeZone;
use Ritmo\Connectors\Support\Payload;
use Ritmo\Contracts\ConnectionStatus;
use Ritmo\Contracts\Exception\ConnectorException;
use Ritmo\Contracts\Exception\WriteBlocked;
use Ritmo\Contracts\HttpClient;
use Ritmo\Contracts\Logger;
use Ritmo\Contracts\PmsConnector;
use Ritmo\Contracts\WriteAuthority;
use Ritmo\Contracts\WriteOperation;
use Ritmo\Contracts\WriteRequest;
use Ritmo\Contracts\WriteToken;
use Ritmo\Domain\Model\BookingRecord;
use Ritmo\Domain\Support\Clock;
use Ritmo\Domain\Support\DateRange;
use Ritmo\Domain\Support\Days;

/**
 * Beds24 (API V2) as the PMS: bookings and the inventory calendar.
 *
 * Reads are GET. The only write is the calendar (CALENDAR_PATH): availability, stay, the
 * arrival/departure restriction and channel limits, built from a whitelist (CalendarWriteBody),
 * allowed by the write authority for exactly those rooms, nights and fields, and sent once. A
 * price is never written here and bookings are never written by the plugin at all: there is no
 * method for it and no booking route on the write allow-list. Without a write authority the
 * connector is read-only.
 */
final class Beds24Connector implements PmsConnector
{
    public const ID = 'beds24';

    /** The one write route, relative to the base URL. */
    public const CALENDAR_PATH = '/inventory/rooms/calendar';

    public const BASE_URL = 'https://api.beds24.com/v2';

    private readonly Beds24Client $client;

    private readonly BookingMapper $bookings;

    private readonly InventoryMapper $inventory;

    public function __construct(
        HttpClient $http,
        private readonly Beds24Config $config,
        Clock $clock,
        Logger $logger,
        private readonly ?WriteAuthority $authority = null,
    ) {
        $this->client = new Beds24Client($http, $config, $clock, $logger);
        $this->bookings = new BookingMapper($config->roomMap);
        $this->inventory = new InventoryMapper();
    }

    public function id(): string
    {
        return self::ID;
    }

    public function testConnection(): ConnectionStatus
    {
        try {
            $properties = $this->client->all('/properties');
        } catch (ConnectorException $e) {
            return new ConnectionStatus(false, $e->getMessage(), array_filter(['http_status' => $e->httpStatus()], 'is_int'));
        }

        $names = array_values(array_filter(array_map(static fn (array $property): string => Payload::text($property, 'name', 80), $properties)));

        return new ConnectionStatus(
            true,
            sprintf('Connected to Beds24: %d %s visible.', count($properties), count($properties) === 1 ? 'property' : 'properties'),
            ['properties' => count($properties), 'property_names' => implode(', ', $names)]
        );
    }

    public function readBookingsByStay(DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $range = new DateRange($from, $to);

        // The API filters by arrival and departure days; the exact "has a night in the range"
        // is decided here, on the mapped booking.
        $found = $this->readBookings([
            'arrivalTo' => Days::key($range->last()),
            'departureFrom' => Days::key($range->first()),
        ]);

        return array_values(array_filter(
            $found,
            static fn (BookingRecord $booking): bool => $booking->nights()->intersect($range) !== null
        ));
    }

    public function readBookingsModifiedSince(DateTimeImmutable $instant): array
    {
        $since = $instant->setTimezone(new DateTimeZone('UTC'));
        $found = $this->readBookings(['modifiedFrom' => $since->format('Y-m-d\TH:i:s\Z')]);

        // A booking without any timestamp is kept: leaving out a change is worse than repeating one.
        return array_values(array_filter(
            $found,
            static function (BookingRecord $booking) use ($since): bool {
                $changedAt = $booking->modifiedAt ?? $booking->createdAt;

                return $changedAt === null || $changedAt >= $since;
            }
        ));
    }

    public function readInventory(array $roomRefs, DateTimeImmutable $from, DateTimeImmutable $to, bool $withPrices = false): array
    {
        $range = new DateRange($from, $to);
        $roomIds = array_values(array_unique(array_map(static fn (mixed $ref): string => Beds24Config::id($ref, 'room'), $roomRefs)));
        if ($roomIds === []) {
            return [];
        }

        $query = [
            'roomId' => $roomIds,
            'startDate' => Days::key($range->first()),
            'endDate' => Days::key($range->last()),
            'includeNumAvail' => true,
            'includeMinStay' => true,
            'includeMaxStay' => true,
            'includeOverride' => true,
        ];
        if ($withPrices) {
            $query['includePrices'] = true;
        }

        // A room may come in more than one item (one per page); its ranges are put together first.
        $spans = array_fill_keys($roomIds, []);
        foreach ($this->client->all(self::CALENDAR_PATH, $query) as $room) {
            $roomId = Payload::text($room, 'roomId', 64);
            if (isset($spans[$roomId])) {
                array_push($spans[$roomId], ...Payload::rows($room, 'calendar'));
            }
        }

        return array_map(fn (array $calendar): array => $this->inventory->nights($calendar, $range), $spans);
    }

    /**
     * @param list<array<string, mixed>> $changes ranges in the API's fields: roomId, from, to and the values to set
     *                                         (numAvail, minStay, maxStay, override, channels)
     */
    public function writeCalendar(WriteToken $token, array $changes): void
    {
        $body = CalendarWriteBody::build($changes, $this->config->roomUnits);
        if ($this->authority === null) {
            throw WriteBlocked::forRequest('POST', self::CALENDAR_PATH, 'the connector was built read-only');
        }
        $this->authority->verify($token, new WriteRequest(WriteOperation::PmsCalendar, CalendarWriteBody::nights($body), CalendarWriteBody::fields($body), WriteRequest::entriesOf($changes)));
        $this->client->post($token, self::CALENDAR_PATH, $body);
    }

    /**
     * Bookings of every status matching the filter, with their invoice items (the source of
     * the accommodation revenue).
     *
     * @param array<string, scalar> $filter
     *
     * @return list<BookingRecord>
     *
     * @throws ConnectorException
     */
    private function readBookings(array $filter): array
    {
        $query = $filter + ['status' => BookingMapper::apiStatuses(), 'includeInvoiceItems' => true];
        if ($this->config->propertyIds !== []) {
            $query['propertyId'] = $this->config->propertyIds;
        }

        return array_map($this->bookings->map(...), $this->client->all('/bookings', $query));
    }
}
