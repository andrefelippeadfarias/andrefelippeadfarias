<?php

declare(strict_types=1);

namespace Ritmo\Domain\Report\Booking;

use Ritmo\Domain\Model\BookingRecord;

/**
 * Turns stored bookings into the lines the reports count.
 */
final class BookingLines
{
    private function __construct()
    {
    }

    /**
     * The counted lines (active and cancelled bookings), in the order given.
     *
     * @param iterable<BookingRecord> $bookings
     * @param array<string, string>   $roomProperty room code => property id
     *
     * @return list<BookingLine>
     */
    public static function of(iterable $bookings, array $roomProperty, string $timezone): array
    {
        $lines = [];
        foreach ($bookings as $booking) {
            $line = BookingLine::of($booking, $roomProperty, $timezone);
            if ($line->isCounted()) {
                $lines[] = $line;
            }
        }

        return $lines;
    }

    /**
     * @param list<BookingLine> $lines
     *
     * @return list<BookingLine>
     */
    public static function ofProperty(array $lines, ?string $propertyId): array
    {
        if ($propertyId === null) {
            return $lines;
        }

        return array_values(array_filter($lines, static fn (BookingLine $l): bool => $l->propertyId === $propertyId));
    }
}
