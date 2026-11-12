<?php

declare(strict_types=1);

namespace Ritmo\Domain\Model;

use DateTimeImmutable;
use InvalidArgumentException;
use Ritmo\Domain\Support\DateRange;
use Ritmo\Domain\Support\Days;

/**
 * A booking as the plugin keeps it.
 *
 * By design there is no guest name, contact, address or free text here: connectors drop those
 * fields when they map a PMS booking, so guest data never reaches storage, logs or the AI.
 */
final class BookingRecord
{
    public readonly DateTimeImmutable $arrival;

    public readonly DateTimeImmutable $departure;

    /**
     * @param string                 $pmsRef     id of the booking in the PMS
     * @param string|null            $roomCode   code of the room type; null when the PMS room is not mapped
     * @param DateTimeImmutable      $arrival    date-only
     * @param DateTimeImmutable      $departure  date-only, after the arrival
     * @param string                 $channel    sales channel, lowercase ("booking", "airbnb", "direct")
     * @param string                 $origin     what created the booking in the PMS (integration or user login)
     * @param float|null             $total      booking total in the property currency; null when the PMS has none
     * @param DateTimeImmutable|null $createdAt  instant in UTC
     * @param DateTimeImmutable|null $modifiedAt instant in UTC
     * @param DateTimeImmutable|null $cancelledAt instant in UTC
     * @param string                 $roomPmsRef id of the room in the PMS, kept even when not mapped
     * @param int                    $units      units of the room type the booking takes
     *
     * @throws InvalidArgumentException on an empty reference, a departure not after the arrival or invalid counts
     */
    public function __construct(
        public readonly string $pmsRef,
        public readonly ?string $roomCode,
        DateTimeImmutable $arrival,
        DateTimeImmutable $departure,
        public readonly BookingStatus $status,
        public readonly string $channel,
        public readonly string $origin,
        public readonly ?float $total,
        public readonly ?DateTimeImmutable $createdAt,
        public readonly ?DateTimeImmutable $modifiedAt,
        public readonly ?DateTimeImmutable $cancelledAt,
        public readonly string $roomPmsRef = '',
        public readonly int $units = 1,
    ) {
        if ($pmsRef === '') {
            throw new InvalidArgumentException('A booking needs its PMS reference.');
        }
        if ($units < 1) {
            throw new InvalidArgumentException('A booking takes at least one unit.');
        }
        if ($total !== null && !is_finite($total)) {
            throw new InvalidArgumentException('The booking total must be a finite number.');
        }
        $this->arrival = Days::normalize($arrival);
        $this->departure = Days::normalize($departure);
        if (Days::diff($this->arrival, $this->departure) < 1) {
            throw new InvalidArgumentException('A booking must depart after it arrives.');
        }
    }

    /**
     * The nights the booking covers (arrival to the day before departure).
     */
    public function nights(): DateRange
    {
        return DateRange::stay($this->arrival, $this->departure);
    }
}
