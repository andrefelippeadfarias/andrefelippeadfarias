<?php

declare(strict_types=1);

namespace Ritmo\Domain\Report\Booking;

use Ritmo\Domain\Support\Num;

/**
 * Totals of a list of booking lines: how many, how many cancelled and active, room nights
 * (nights x units), revenue and average daily rate of the active ones, mean lead time and mean
 * stay, and the revenue that left with the cancellations.
 *
 * Reference: desempenho_mes.agrega. A line without a creation instant has no lead time and is
 * left out of the mean lead time (the reference reads such a booking as made in 1970).
 */
final class BookingTotals
{
    public function __construct(
        public readonly int $bookings,
        public readonly int $cancelled,
        public readonly int $active,
        public readonly int $roomNights,
        public readonly float $revenue,
        public readonly ?float $adr,
        public readonly ?float $meanLeadDays,
        public readonly ?float $meanStayNights,
        public readonly float $cancelledRevenue,
        public readonly int $cancelledWithoutValue,
    ) {
    }

    /**
     * @param list<BookingLine> $lines
     */
    public static function of(array $lines): self
    {
        $active = array_values(array_filter($lines, static fn (BookingLine $l): bool => $l->isActive()));
        $cancelled = array_values(array_filter($lines, static fn (BookingLine $l): bool => $l->isCancelled()));
        $roomNights = 0;
        $revenue = 0.0;
        $nights = 0;
        $lead = 0;
        $withLead = 0;
        foreach ($active as $line) {
            $roomNights += $line->nights * $line->units;
            $revenue += $line->revenue;
            $nights += $line->nights;
            if ($line->leadDays !== null) {
                $lead += $line->leadDays;
                ++$withLead;
            }
        }
        $lost = 0.0;
        $withoutValue = 0;
        foreach ($cancelled as $line) {
            $lost += $line->revenue;
            $withoutValue += $line->revenue == 0.0 ? 1 : 0;
        }

        return new self(
            count($lines),
            count($cancelled),
            count($active),
            $roomNights,
            Num::roundHalfEven($revenue, 2),
            $roomNights > 0 ? Num::roundHalfEven($revenue / $roomNights, 2) : null,
            $withLead > 0 ? Num::roundHalfEven($lead / $withLead, 1) : null,
            $active !== [] ? Num::roundHalfEven($nights / count($active), 2) : null,
            Num::roundHalfEven($lost, 2),
            $withoutValue,
        );
    }

    /**
     * @return array<string, int|float|null>
     */
    public function toArray(): array
    {
        return [
            'bookings' => $this->bookings,
            'cancelled' => $this->cancelled,
            'active' => $this->active,
            'room_nights' => $this->roomNights,
            'revenue' => $this->revenue,
            'adr' => $this->adr,
            'mean_lead_days' => $this->meanLeadDays,
            'mean_stay_nights' => $this->meanStayNights,
            'cancelled_revenue' => $this->cancelledRevenue,
            'cancelled_without_value' => $this->cancelledWithoutValue,
        ];
    }
}
