<?php

declare(strict_types=1);

namespace Ritmo\Domain\Operations;

use DateTimeImmutable;
use DateTimeInterface;
use Ritmo\Domain\Model\BookingRecord;
use Ritmo\Domain\Model\BookingStatus;
use Ritmo\Domain\Support\DateRange;
use Ritmo\Domain\Support\Days;

/**
 * The facts of one version of a booking that make it movement when they change: status, room,
 * dates, units, total and the PMS timestamps. Its fingerprint identifies the version, so a booking
 * read again in the overlap of two reads is never counted twice. No guest data.
 */
final class BookingVersion
{
    public readonly DateTimeImmutable $arrival;

    public readonly DateTimeImmutable $departure;

    public function __construct(
        public readonly string $pmsRef,
        public readonly ?string $roomCode,
        DateTimeImmutable $arrival,
        DateTimeImmutable $departure,
        public readonly BookingStatus $status,
        public readonly int $units,
        public readonly ?float $total,
        public readonly ?DateTimeImmutable $modifiedAt,
        public readonly ?DateTimeImmutable $cancelledAt,
    ) {
        $this->arrival = Days::normalize($arrival);
        $this->departure = Days::normalize($departure);
    }

    public static function of(BookingRecord $booking): self
    {
        return new self(
            $booking->pmsRef,
            $booking->roomCode,
            $booking->arrival,
            $booking->departure,
            $booking->status,
            $booking->units,
            $booking->total,
            $booking->modifiedAt,
            $booking->cancelledAt,
        );
    }

    public function fingerprint(): string
    {
        return hash('sha256', implode('|', [
            $this->pmsRef,
            $this->roomCode ?? '',
            Days::key($this->arrival),
            Days::key($this->departure),
            $this->status->value,
            (string) $this->units,
            $this->total === null ? '' : sprintf('%.2F', $this->total),
            self::instant($this->modifiedAt),
            self::instant($this->cancelledAt),
        ]));
    }

    public function nights(): DateRange
    {
        return DateRange::stay($this->arrival, $this->departure);
    }

    private static function instant(?DateTimeInterface $instant): string
    {
        return $instant === null ? '' : (string) $instant->getTimestamp();
    }
}
