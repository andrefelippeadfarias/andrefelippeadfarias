<?php

declare(strict_types=1);

namespace Ritmo\Connectors\Beds24;

use Ritmo\Connectors\Support\HttpFailure;
use Ritmo\Connectors\Support\Payload;
use Ritmo\Contracts\Exception\ConnectorException;
use Ritmo\Domain\Model\BookingRecord;
use Ritmo\Domain\Model\BookingStatus;
use Ritmo\Domain\Support\Days;

/**
 * Turns a Beds24 booking into a BookingRecord.
 *
 * It reads a closed list of fields (ids, dates, status, amounts, channel, timestamps) and
 * nothing else: names, contacts, addresses, comments, notes and every other free text of the
 * booking are never copied, so they cannot reach storage, the log or a screen.
 */
final class BookingMapper
{
    private const OPERATION = 'GET /bookings';

    /** Beds24 statuses and what they are in the plugin. "black" is a blocked period. */
    private const STATUSES = [
        'confirmed' => BookingStatus::Confirmed,
        'new' => BookingStatus::NewBooking,
        'request' => BookingStatus::Request,
        'cancelled' => BookingStatus::Cancelled,
        'black' => BookingStatus::Blocked,
        'inquiry' => BookingStatus::Inquiry,
    ];

    private const ORIGIN_MAX_LENGTH = 64;

    /**
     * @param array<string, string> $roomMap Beds24 room id => room type code
     */
    public function __construct(private readonly array $roomMap)
    {
    }

    /**
     * Every status Beds24 has, as the API spells them.
     *
     * @return list<string>
     */
    public static function apiStatuses(): array
    {
        return array_keys(self::STATUSES);
    }

    /**
     * @param array<int|string, mixed> $booking one item of the API's booking list
     *
     * @throws ConnectorException when the booking lacks its id, room, dates or a known status
     */
    public function map(array $booking): BookingRecord
    {
        $id = Payload::text($booking, 'id', 64);
        $roomId = Payload::text($booking, 'roomId', 64);
        $arrival = Payload::date($booking, 'arrival');
        $departure = Payload::date($booking, 'departure');
        $status = self::STATUSES[strtolower(Payload::text($booking, 'status', 32))] ?? null;
        if ($id === '' || $roomId === '' || $arrival === null || $departure === null || $status === null) {
            throw HttpFailure::unexpectedShape(Beds24Connector::ID, 'Beds24', self::OPERATION, 'a booking lacks its id, room, dates or a known status');
        }

        // The PMS counts at least one night for any booking, as its own occupancy report does.
        if (Days::diff($arrival, $departure) < 1) {
            $departure = Days::add($arrival, 1);
        }

        $channel = strtolower(Payload::text($booking, 'channel', 32));

        return new BookingRecord(
            $id,
            $this->roomMap[$roomId] ?? null,
            $arrival,
            $departure,
            $status,
            $channel === '' ? 'direct' : $channel,
            Payload::text($booking, 'referer', self::ORIGIN_MAX_LENGTH),
            self::total($booking),
            Payload::instant($booking, 'bookingTime'),
            Payload::instant($booking, 'modifiedTime'),
            Payload::instant($booking, 'cancelTime'),
            $roomId,
            Payload::count($booking, 'roomQty') ?? 1
        );
    }

    /**
     * Accommodation revenue of the booking: the sum of its invoice charges when it has any,
     * otherwise its price. Payments and charges billed to another invoicee are not revenue of
     * the stay.
     *
     * @param array<int|string, mixed> $booking
     */
    private static function total(array $booking): ?float
    {
        $charges = array_filter(
            Payload::rows($booking, 'invoiceItems'),
            static fn (array $item): bool => strtolower(Payload::text($item, 'type', 32)) === 'charge' && empty($item['invoiceeId'])
        );
        if ($charges === []) {
            return Payload::number($booking, 'price');
        }

        $total = 0.0;
        foreach ($charges as $item) {
            $quantity = Payload::number($item, 'qty');
            $total += Payload::number($item, 'lineTotal')
                ?? (Payload::number($item, 'amount') ?? 0.0) * ($quantity === null || $quantity === 0.0 ? 1.0 : $quantity);
        }

        return $total;
    }
}
