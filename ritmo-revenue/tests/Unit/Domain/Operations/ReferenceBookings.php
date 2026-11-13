<?php

declare(strict_types=1);

namespace Ritmo\Tests\Unit\Domain\Operations;

use Ritmo\Connectors\Beds24\BookingMapper;
use Ritmo\Domain\Model\BookingRecord;

/**
 * Turns the reference's booking dictionaries of the operations goldens into BookingRecord through
 * the PMS mapper, with the neutral room ids of the cases.
 */
final class ReferenceBookings
{
    public const ROOMS = ['9001' => 'room-a', '9002' => 'room-b', '9101' => 'room-x', '9102' => 'room-y'];

    /** The reference's movement kinds and statuses in the plugin's vocabulary. */
    public const KINDS = ['nova' => 'new', 'alterada' => 'modified', 'cancelada' => 'cancelled'];

    public const STATUSES = ['black' => 'blocked'];

    /**
     * @param array<string, mixed> $booking
     */
    public static function one(array $booking): BookingRecord
    {
        return (new BookingMapper(self::ROOMS))->map($booking);
    }

    /**
     * @param list<array<string, mixed>> $bookings
     *
     * @return list<BookingRecord>
     */
    public static function all(array $bookings): array
    {
        return array_map(self::one(...), $bookings);
    }
}
