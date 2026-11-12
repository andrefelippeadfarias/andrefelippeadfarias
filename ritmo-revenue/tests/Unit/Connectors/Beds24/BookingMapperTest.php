<?php

declare(strict_types=1);

namespace Ritmo\Tests\Unit\Connectors\Beds24;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Ritmo\Connectors\Beds24\BookingMapper;
use Ritmo\Contracts\Exception\ConnectorException;
use Ritmo\Domain\Model\BookingStatus;

final class BookingMapperTest extends TestCase
{
    /**
     * A booking as Beds24 sends it, guest fields included (marked GUESTDATA).
     *
     * @param array<string, mixed> $changes
     *
     * @return array<string, mixed>
     */
    public static function booking(array $changes = []): array
    {
        return $changes + [
            'id' => 7101,
            'propertyId' => 901001,
            'roomId' => 902001,
            'unitId' => 2,
            'roomQty' => 1,
            'status' => 'confirmed',
            'arrival' => '2027-03-05',
            'departure' => '2027-03-07',
            'price' => 1120,
            'channel' => 'booking',
            'referer' => 'Booking.com',
            'bookingTime' => '2027-01-10T14:22:05Z',
            'modifiedTime' => '2027-01-11T08:00:00Z',
            'cancelTime' => null,
            'invoiceItems' => [],
            'firstName' => 'GUESTDATA-first',
            'lastName' => 'GUESTDATA-last',
            'email' => 'guestdata@example.invalid',
            'phone' => '+00 GUESTDATA',
            'mobile' => '+00 GUESTDATA mobile',
            'address' => 'GUESTDATA street',
            'city' => 'GUESTDATA city',
            'comments' => 'GUESTDATA comment',
            'notes' => 'GUESTDATA note',
            'message' => 'GUESTDATA message',
            'guests' => [['firstName' => 'GUESTDATA-companion']],
            'infoItems' => [['code' => 'NOTE', 'text' => 'GUESTDATA info']],
        ];
    }

    public function testTheBookingIsMappedFieldByField(): void
    {
        $record = (new BookingMapper(['902001' => 'STD']))->map(self::booking(['roomQty' => '2', 'channel' => 'Booking']));

        self::assertSame('7101', $record->pmsRef);
        self::assertSame('STD', $record->roomCode);
        self::assertSame('902001', $record->roomPmsRef);
        self::assertSame('2027-03-05', $record->arrival->format('Y-m-d'));
        self::assertSame('2027-03-07', $record->departure->format('Y-m-d'));
        self::assertSame(BookingStatus::Confirmed, $record->status);
        self::assertSame('booking', $record->channel);
        self::assertSame('Booking.com', $record->origin);
        self::assertSame(1120.0, $record->total);
        self::assertSame(2, $record->units);
        self::assertSame('2027-01-10T14:22:05+00:00', $record->createdAt?->format('c'));
        self::assertSame('2027-01-11T08:00:00+00:00', $record->modifiedAt?->format('c'));
        self::assertNull($record->cancelledAt);
        self::assertSame(['2027-03-05', '2027-03-06'], $record->nights()->keys());
    }

    public function testNoGuestFieldReachesTheRecord(): void
    {
        $record = (new BookingMapper(['902001' => 'STD']))->map(self::booking());

        $dump = print_r($record, true);
        self::assertStringNotContainsStringIgnoringCase('GUESTDATA', $dump);
        self::assertStringNotContainsString('example.invalid', $dump);
        foreach (['firstName', 'lastName', 'email', 'phone', 'address', 'comments', 'notes', 'guests'] as $field) {
            self::assertFalse(property_exists($record, $field), $field);
        }
    }

    /**
     * @return array<string, array{string, BookingStatus}>
     */
    public static function statuses(): array
    {
        return [
            'confirmed' => ['confirmed', BookingStatus::Confirmed],
            'new' => ['new', BookingStatus::NewBooking],
            'request' => ['request', BookingStatus::Request],
            'cancelled' => ['cancelled', BookingStatus::Cancelled],
            'black (blocked period)' => ['black', BookingStatus::Blocked],
            'inquiry' => ['inquiry', BookingStatus::Inquiry],
            'any case' => ['Confirmed', BookingStatus::Confirmed],
        ];
    }

    #[DataProvider('statuses')]
    public function testEveryBeds24StatusIsMapped(string $apiStatus, BookingStatus $expected): void
    {
        self::assertSame($expected, (new BookingMapper([]))->map(self::booking(['status' => $apiStatus]))->status);
    }

    public function testTheApiStatusesAreEveryStatus(): void
    {
        self::assertSame(['confirmed', 'new', 'request', 'cancelled', 'black', 'inquiry'], BookingMapper::apiStatuses());
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function unreadable(): array
    {
        return [
            'no id' => [['id' => null]],
            'no room' => [['roomId' => '']],
            'no arrival' => [['arrival' => null]],
            'bad departure' => [['departure' => '2027-02-30']],
            'unknown status' => [['status' => 'tentative']],
        ];
    }

    /**
     * @param array<string, mixed> $changes
     */
    #[DataProvider('unreadable')]
    public function testABookingWithoutItsEssentialsIsAnError(array $changes): void
    {
        $this->expectException(ConnectorException::class);
        $this->expectExceptionMessage('outside the documented format');

        (new BookingMapper([]))->map(self::booking($changes));
    }

    public function testTheTotalIsTheSumOfTheAccommodationCharges(): void
    {
        $record = (new BookingMapper([]))->map(self::booking(['price' => 9999, 'invoiceItems' => [
            ['type' => 'charge', 'qty' => 2, 'amount' => 500, 'lineTotal' => 1000, 'invoiceeId' => null],
            ['type' => 'Charge', 'qty' => 3, 'amount' => 40, 'lineTotal' => null],
            ['type' => 'charge', 'qty' => 0, 'amount' => 15],
            ['type' => 'charge', 'qty' => 1, 'amount' => 150, 'lineTotal' => 150, 'invoiceeId' => 55],
            ['type' => 'payment', 'qty' => 1, 'amount' => 1000, 'lineTotal' => 1000],
        ]]));

        self::assertSame(1000.0 + 120.0 + 15.0, $record->total, 'lineTotal, else amount x qty (qty 0 counts once); other invoicees and payments are not revenue');
    }

    public function testWithoutChargesTheTotalIsThePrice(): void
    {
        $mapper = new BookingMapper([]);

        self::assertSame(840.5, $mapper->map(self::booking(['price' => '840.5', 'invoiceItems' => [['type' => 'payment', 'amount' => 840.5]]]))->total);
        self::assertNull($mapper->map(self::booking(['price' => null, 'invoiceItems' => null]))->total);
    }

    public function testChannelOriginUnitsAndRoomFallbacks(): void
    {
        $record = (new BookingMapper(['902002' => 'SUITE']))->map(self::booking(['channel' => '', 'referer' => null, 'roomQty' => null, 'bookingTime' => 'not a time', 'modifiedTime' => null]));

        self::assertSame('direct', $record->channel);
        self::assertSame('', $record->origin);
        self::assertSame(1, $record->units);
        self::assertNull($record->roomCode, 'room 902001 is not mapped');
        self::assertSame('902001', $record->roomPmsRef);
        self::assertNull($record->createdAt);
        self::assertNull($record->modifiedAt);
    }

    public function testABookingWithoutNightsCountsOne(): void
    {
        $record = (new BookingMapper([]))->map(self::booking(['arrival' => '2027-03-05', 'departure' => '2027-03-05']));

        self::assertSame('2027-03-06', $record->departure->format('Y-m-d'));
        self::assertSame(1, $record->nights()->count());
    }

    public function testACancelledBookingKeepsItsCancellationTime(): void
    {
        $record = (new BookingMapper([]))->map(self::booking(['status' => 'cancelled', 'cancelTime' => '2027-02-26T08:30:00Z']));

        self::assertSame(BookingStatus::Cancelled, $record->status);
        self::assertSame('2027-02-26T08:30:00+00:00', $record->cancelledAt?->format('c'));
    }
}
