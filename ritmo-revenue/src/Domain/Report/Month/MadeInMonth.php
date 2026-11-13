<?php

declare(strict_types=1);

namespace Ritmo\Domain\Report\Month;

use Ritmo\Domain\Report\Booking\BookingLine;
use Ritmo\Domain\Report\Booking\BookingTotals;
use Ritmo\Domain\Report\Booking\Grouping;
use Ritmo\Domain\Support\Days;

/**
 * Bookings made in a month (by their creation instant) for one property or the whole site:
 * the totals overall and by channel, room, week of the month and month of the stay.
 */
final class MadeInMonth
{
    /**
     * @param array<string, BookingTotals> $byChannel
     * @param array<string, BookingTotals> $byRoom
     * @param array<int, BookingTotals>    $byWeek      week of the month (1-5) => totals
     * @param array<string, BookingTotals> $byStayMonth "YYYY-MM" of the arrival => totals
     */
    public function __construct(
        public readonly ?string $propertyId,
        public readonly BookingTotals $overall,
        public readonly array $byChannel,
        public readonly array $byRoom,
        public readonly array $byWeek,
        public readonly array $byStayMonth,
    ) {
    }

    /**
     * @param list<BookingLine> $lines made in the month, of this scope
     */
    public static function of(?string $propertyId, array $lines, string $timezone): self
    {
        return new self(
            $propertyId,
            BookingTotals::of($lines),
            Grouping::totals($lines, static fn (BookingLine $l): string => $l->channel),
            Grouping::totals($lines, static fn (BookingLine $l): string => $l->roomCode ?? ''),
            Grouping::totals($lines, static fn (BookingLine $l): int => self::weekOfMonth($l, $timezone)),
            Grouping::totals($lines, static fn (BookingLine $l): string => Period::keyOf($l->arrival)),
        );
    }

    /**
     * (day of the month at the property - 1) div 7 + 1.
     */
    private static function weekOfMonth(BookingLine $line, string $timezone): int
    {
        $day = $line->createdAt === null ? 1 : (int) Days::localDate($line->createdAt, $timezone)->format('j');

        return intdiv($day - 1, 7) + 1;
    }
}
