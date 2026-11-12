<?php

declare(strict_types=1);

namespace Ritmo\Tests\Integration\Db;

use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Ritmo\Domain\Model\BookingRecord;
use Ritmo\Domain\Model\BookingStatus;
use Ritmo\Domain\Support\Days;
use Ritmo\Infrastructure\Db\PdoDatabase;
use Ritmo\Infrastructure\Db\Repository\BookingRepository;
use Ritmo\Tests\Unit\Infrastructure\Support\MigratedDatabase;

final class BookingRepositoryTest extends TestCase
{
    private PdoDatabase $db;

    private BookingRepository $bookings;

    protected function setUp(): void
    {
        $this->db = MigratedDatabase::create();
        $this->bookings = new BookingRepository($this->db);
    }

    public function testABookingIsStoredAndReadBackEqual(): void
    {
        $booking = new BookingRecord(
            'B-1001',
            'suite',
            Days::date('2026-12-24'),
            Days::date('2026-12-27'),
            BookingStatus::Confirmed,
            'booking',
            'channel manager',
            1890.4,
            new DateTimeImmutable('2026-11-01T10:15:30Z'),
            new DateTimeImmutable('2026-11-02T08:00:00Z'),
            null,
            '901',
            2
        );

        $this->bookings->upsert('pms-a', $booking, new DateTimeImmutable('2026-11-11T12:00:00Z'));

        self::assertEquals($booking, $this->bookings->find('pms-a', 'B-1001'));
        self::assertNull($this->bookings->find('pms-b', 'B-1001'));
        self::assertNull($this->bookings->find('pms-a', 'B-9999'));
    }

    public function testUpsertReplacesTheBookingWithTheSamePmsReference(): void
    {
        $this->bookings->upsert('pms-a', $this->booking('B-1', 'suite', '2026-12-24', '2026-12-27'), new DateTimeImmutable('2026-11-11T12:00:00Z'));
        $cancelled = new BookingRecord(
            'B-1',
            null,
            Days::date('2026-12-25'),
            Days::date('2026-12-26'),
            BookingStatus::Cancelled,
            'direct',
            '',
            null,
            null,
            new DateTimeImmutable('2026-11-12T09:00:00Z'),
            new DateTimeImmutable('2026-11-12T09:00:00Z'),
            '777'
        );

        $this->bookings->upsert('pms-a', $cancelled, new DateTimeImmutable('2026-11-12T12:00:00Z'));

        self::assertSame('1', $this->db->selectValue('SELECT COUNT(*) FROM wp_ritmo_bookings'));
        self::assertEquals($cancelled, $this->bookings->find('pms-a', 'B-1'));
        self::assertSame('2026-11-12 12:00:00', $this->db->selectValue('SELECT synced_at FROM wp_ritmo_bookings'));
    }

    public function testTheSameReferenceInTwoPmsIsTwoBookings(): void
    {
        $at = new DateTimeImmutable('2026-11-11T12:00:00Z');
        $this->bookings->upsert('pms-a', $this->booking('B-1', 'suite', '2026-12-24', '2026-12-27'), $at);
        $this->bookings->upsert('pms-b', $this->booking('B-1', 'loft', '2026-12-01', '2026-12-02'), $at);

        self::assertSame('suite', $this->bookings->find('pms-a', 'B-1')?->roomCode);
        self::assertSame('loft', $this->bookings->find('pms-b', 'B-1')?->roomCode);
    }

    public function testUpsertManyStoresAllOrNothing(): void
    {
        $at = new DateTimeImmutable('2026-11-11T12:00:00Z');
        self::assertSame(2, $this->bookings->upsertMany('pms-a', [
            $this->booking('B-1', 'suite', '2026-12-24', '2026-12-27'),
            $this->booking('B-2', 'suite', '2026-12-27', '2026-12-28'),
        ], $at));

        try {
            $this->bookings->upsertMany('pms-a', [
                $this->booking('B-3', 'suite', '2026-12-28', '2026-12-29'),
                $this->booking(str_repeat('X', 65), 'suite', '2026-12-29', '2026-12-30'),
            ], $at);
            self::fail('A reference longer than its column must be refused.');
        } catch (InvalidArgumentException) {
        }

        self::assertNull($this->bookings->find('pms-a', 'B-3'));
        self::assertSame('2', $this->db->selectValue('SELECT COUNT(*) FROM wp_ritmo_bookings'));
    }

    public function testOverlappingReturnsEveryStatusWithANightInTheRange(): void
    {
        $at = new DateTimeImmutable('2026-11-11T12:00:00Z');
        $this->bookings->upsertMany('pms-a', [
            $this->booking('before', 'suite', '2026-12-08', '2026-12-10'),   // nights 8, 9: ends on the day the range starts
            $this->booking('into', 'suite', '2026-12-09', '2026-12-11'),     // night 10 is in the range
            $this->booking('inside', 'loft', '2026-12-11', '2026-12-12'),
            $this->booking('out-of', 'suite', '2026-12-12', '2026-12-15'),   // night 12 is the last night of the range
            $this->booking('after', 'suite', '2026-12-13', '2026-12-14'),
            $this->booking('cancelled', 'suite', '2026-12-10', '2026-12-11', BookingStatus::Cancelled),
            $this->booking('unmapped', null, '2026-12-10', '2026-12-11'),
        ], $at);

        $all = $this->bookings->overlapping(Days::date('2026-12-10'), Days::date('2026-12-12'));
        self::assertSame(['into', 'cancelled', 'unmapped', 'inside', 'out-of'], array_map(static fn (BookingRecord $booking): string => $booking->pmsRef, $all));

        $suite = $this->bookings->overlapping(Days::date('2026-12-10'), Days::date('2026-12-12'), 'suite');
        self::assertSame(['into', 'cancelled', 'out-of'], array_map(static fn (BookingRecord $booking): string => $booking->pmsRef, $suite));

        self::assertSame([], $this->bookings->overlapping(Days::date('2027-01-01'), Days::date('2027-01-31')));
    }

    public function testTheLatestModificationIsTheCursorOfEachPms(): void
    {
        self::assertNull($this->bookings->latestModification('pms-a'));

        $at = new DateTimeImmutable('2026-11-11T12:00:00Z');
        $this->bookings->upsert('pms-a', $this->booking('B-1', 'suite', '2026-12-24', '2026-12-27', BookingStatus::Confirmed, '2026-11-02T08:00:00Z'), $at);
        $this->bookings->upsert('pms-a', $this->booking('B-2', 'suite', '2026-12-27', '2026-12-28', BookingStatus::Confirmed, '2026-11-05T21:30:10-03:00'), $at);
        $this->bookings->upsert('pms-b', $this->booking('B-3', 'suite', '2026-12-27', '2026-12-28', BookingStatus::Confirmed, '2026-11-09T00:00:00Z'), $at);

        self::assertSame('2026-11-06T00:30:10+00:00', $this->bookings->latestModification('pms-a')?->format(DATE_ATOM));
        self::assertSame('2026-11-09T00:00:00+00:00', $this->bookings->latestModification('pms-b')?->format(DATE_ATOM));
    }

    public function testOldBookingsCanBePurged(): void
    {
        $at = new DateTimeImmutable('2026-11-11T12:00:00Z');
        $this->bookings->upsertMany('pms-a', [
            $this->booking('old', 'suite', '2025-01-01', '2025-01-03'),
            $this->booking('edge', 'suite', '2025-06-28', '2025-06-30'),
            $this->booking('recent', 'suite', '2026-10-01', '2026-10-03'),
        ], $at);

        self::assertSame(1, $this->bookings->purgeDepartedBefore(Days::date('2025-06-30')));
        self::assertNull($this->bookings->find('pms-a', 'old'));
        self::assertNotNull($this->bookings->find('pms-a', 'edge'));
        self::assertNotNull($this->bookings->find('pms-a', 'recent'));
    }

    public function testLongChannelNamesAreCutToTheColumn(): void
    {
        $long = str_repeat('é', 80);
        $booking = new BookingRecord('B-1', 'suite', Days::date('2026-12-24'), Days::date('2026-12-25'), BookingStatus::NewBooking, $long, $long, 10.0, null, null, null);

        $this->bookings->upsert('pms-a', $booking, new DateTimeImmutable('2026-11-11T12:00:00Z'));

        self::assertSame(str_repeat('é', 64), $this->bookings->find('pms-a', 'B-1')?->channel);
        self::assertSame(str_repeat('é', 64), $this->bookings->find('pms-a', 'B-1')?->origin);
    }

    private function booking(string $ref, ?string $room, string $arrival, string $departure, BookingStatus $status = BookingStatus::Confirmed, ?string $modifiedAt = null): BookingRecord
    {
        return new BookingRecord(
            $ref,
            $room,
            Days::date($arrival),
            Days::date($departure),
            $status,
            'direct',
            'front desk',
            500.0,
            new DateTimeImmutable('2026-10-01T00:00:00Z'),
            $modifiedAt === null ? null : new DateTimeImmutable($modifiedAt),
            null,
            '901'
        );
    }
}
