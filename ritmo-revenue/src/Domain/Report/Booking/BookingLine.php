<?php

declare(strict_types=1);

namespace Ritmo\Domain\Report\Booking;

use DateTimeImmutable;
use Ritmo\Domain\Model\BookingRecord;
use Ritmo\Domain\Model\BookingStatus;
use Ritmo\Domain\Support\Days;

/**
 * One booking as the reports count it: property, room, status, channel, arrival, nights, units,
 * revenue, when it was made and cancelled, and the lead time in days at the property.
 *
 * Only ids, dates, counts and amounts: a booking line never carries a guest field.
 *
 * Reference: desempenho_mes.linha.
 */
final class BookingLine
{
    /** Channel of a direct booking that came in through the PMS's API (a booking engine or website). */
    public const DIRECT_API = 'direct_api';
    /** Channel of a direct booking typed into the PMS by a person. */
    public const DIRECT_MANUAL = 'direct_manual';

    private const API_ORIGIN = 'API';

    public function __construct(
        public readonly string $pmsRef,
        public readonly ?string $propertyId,
        public readonly ?string $roomCode,
        public readonly BookingStatus $status,
        public readonly string $channel,
        public readonly DateTimeImmutable $arrival,
        public readonly int $nights,
        public readonly int $units,
        public readonly float $revenue,
        public readonly ?DateTimeImmutable $createdAt,
        public readonly ?int $leadDays,
        public readonly ?DateTimeImmutable $cancelledAt,
    ) {
    }

    /**
     * @param array<string, string> $roomProperty room code => property id
     * @param string                $timezone     the property's timezone (lead days are counted at the property)
     */
    public static function of(BookingRecord $booking, array $roomProperty, string $timezone): self
    {
        $created = $booking->createdAt;
        $lead = $created === null ? null : Days::diff(Days::localDate($created, $timezone), $booking->arrival);
        $cancelled = $booking->cancelledAt
            ?? ($booking->status === BookingStatus::Cancelled ? $booking->modifiedAt : null);

        return new self(
            $booking->pmsRef,
            $booking->roomCode === null ? null : ($roomProperty[$booking->roomCode] ?? null),
            $booking->roomCode,
            $booking->status,
            self::channelOf($booking),
            $booking->arrival,
            $booking->nights()->count(),
            $booking->units,
            $booking->total ?? 0.0,
            $created,
            $lead,
            $cancelled,
        );
    }

    /**
     * The channel as the reports group it: the PMS channel in lowercase, with direct bookings split
     * by their origin (exactly "API" for the booking engine; anything else was typed by a person).
     */
    public static function channelOf(BookingRecord $booking): string
    {
        $channel = strtolower(trim($booking->channel));
        if ($channel === '' || $channel === 'direct') {
            return $booking->origin === self::API_ORIGIN ? self::DIRECT_API : self::DIRECT_MANUAL;
        }

        return $channel;
    }

    /**
     * True for the statuses the reports count: active bookings and cancellations (requests,
     * blocks and inquiries are left out).
     */
    public function isCounted(): bool
    {
        return $this->status->occupies() || $this->status === BookingStatus::Cancelled;
    }

    public function isActive(): bool
    {
        return $this->status->occupies();
    }

    public function isCancelled(): bool
    {
        return $this->status === BookingStatus::Cancelled;
    }

    /**
     * Revenue of one night of the stay (the stay's revenue spread evenly over its nights).
     */
    public function revenuePerNight(): float
    {
        return $this->revenue / $this->nights;
    }
}
