<?php

declare(strict_types=1);

namespace Ritmo\Tests\Unit\Domain\Report;

use PHPUnit\Framework\TestCase;
use Ritmo\Domain\Model\BookingRecord;
use Ritmo\Domain\Model\BookingStatus;
use Ritmo\Domain\Model\PriceNight;
use Ritmo\Domain\Report\BookedUnits;
use Ritmo\Domain\Report\PmsPricingMismatch;
use Ritmo\Domain\Report\SoldMismatch;
use Ritmo\Domain\Support\DateRange;
use Ritmo\Domain\Support\Days;

final class PmsPricingMismatchTest extends TestCase
{
    private static function booking(string $ref, ?string $room, string $arrival, string $departure, BookingStatus $status = BookingStatus::Confirmed, int $units = 1): BookingRecord
    {
        return new BookingRecord($ref, $room, Days::date($arrival), Days::date($departure), $status, 'direct', 'test', null, null, null, null, 'pms-room', $units);
    }

    private static function priceNight(string $night, int $sold, int $total = 4): PriceNight
    {
        return new PriceNight(Days::date($night), 900.0, null, null, null, $sold, $total);
    }

    public function testBookedUnitsCountsTheNightsOfTheBookingsThatOccupy(): void
    {
        $bookings = [
            self::booking('1', 'Deluxe', '2027-03-10', '2027-03-12'),
            self::booking('2', 'Deluxe', '2027-03-11', '2027-03-12', BookingStatus::NewBooking, 2),
            self::booking('3', 'Deluxe', '2027-03-10', '2027-03-12', BookingStatus::Cancelled),
            self::booking('4', 'Deluxe', '2027-03-10', '2027-03-12', BookingStatus::Request),
            self::booking('5', 'Deluxe', '2027-03-10', '2027-03-12', BookingStatus::Blocked),
            self::booking('6', 'Deluxe', '2027-03-10', '2027-03-12', BookingStatus::Inquiry),
            self::booking('7', null, '2027-03-10', '2027-03-12'),
            self::booking('8', 'Standard', '2027-03-08', '2027-03-11'),
            self::booking('9', 'Standard', '2027-03-12', '2027-03-20'),
            self::booking('10', 'Standard', '2027-03-01', '2027-03-05'),
        ];

        $sold = BookedUnits::byRoomNight($bookings, DateRange::fromStrings('2027-03-10', '2027-03-13'));

        self::assertSame(
            [
                'Deluxe' => ['2027-03-10' => 1, '2027-03-11' => 3],
                'Standard' => ['2027-03-10' => 1, '2027-03-12' => 1, '2027-03-13' => 1],
            ],
            $sold,
            'confirmed and new only, departure day excluded, nights outside the range cut, the unmapped room left out'
        );
    }

    public function testFindsTheNightsWhereThePricingSystemDisagreesWithThePms(): void
    {
        $bookings = [
            self::booking('1', 'Deluxe', '2027-03-10', '2027-03-12'),
            self::booking('2', 'Standard', '2027-03-11', '2027-03-12', BookingStatus::NewBooking, 2),
        ];
        $pricing = [
            'Deluxe' => [self::priceNight('2027-03-09', 1), self::priceNight('2027-03-10', 1), self::priceNight('2027-03-11', 0), self::priceNight('2027-03-12', 2), self::priceNight('2027-03-13', 3)],
            'Standard' => [self::priceNight('2027-03-11', 2, 6), self::priceNight('2027-03-12', 1, 6)],
        ];

        $found = (new PmsPricingMismatch())->find($bookings, $pricing, DateRange::fromStrings('2027-03-10', '2027-03-12'));

        self::assertSame(
            [
                ['Deluxe', '2027-03-11', 1, 0, -1],
                ['Deluxe', '2027-03-12', 0, 2, 2],
                ['Standard', '2027-03-12', 0, 1, 1],
            ],
            array_map(static fn (SoldMismatch $mismatch): array => [$mismatch->roomCode, Days::key($mismatch->night), $mismatch->pmsSold, $mismatch->pricingSold, $mismatch->difference()], $found),
            'nights that agree and nights outside the range are absent'
        );
    }

    public function testNothingToReportWhenTheTwoAgree(): void
    {
        $bookings = [self::booking('1', 'Deluxe', '2027-03-10', '2027-03-11')];
        $pricing = ['Deluxe' => [self::priceNight('2027-03-10', 1), self::priceNight('2027-03-11', 0)]];

        self::assertSame([], (new PmsPricingMismatch())->find($bookings, $pricing, DateRange::fromStrings('2027-03-10', '2027-03-11')));
        self::assertSame([], (new PmsPricingMismatch())->find($bookings, [], DateRange::fromStrings('2027-03-10', '2027-03-11')), 'only the nights the pricing system reports are compared');
    }
}
