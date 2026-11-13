<?php

declare(strict_types=1);

namespace Ritmo\Application\Alerts;

use DateTimeImmutable;
use Ritmo\Domain\Model\BookingRecord;
use Ritmo\Domain\Model\InventoryNight;
use Ritmo\Domain\Support\DateRange;

/**
 * What one run hands to the watch: the bookings of its one movement read (the same list for the
 * movement, the security watch and the bookings below expected), the instant that read covers
 * from, and the PMS inventory it read with the units booked on those nights.
 */
final class WatchInput
{
    /**
     * @param list<BookingRecord>                          $bookings    the movement read
     * @param array<string, array<string, InventoryNight>> $inventory   room code => 'YYYY-MM-DD' => night, read by this run
     * @param array<string, array<string, int>>            $bookedUnits room code => 'YYYY-MM-DD' => units booked (stored bookings)
     * @param array<string, int>                           $roomUnits   room code => units of the room type
     * @param DateRange                                    $checked     the nights the inventory read covers
     */
    public function __construct(
        public readonly string $propertyId,
        public readonly array $bookings,
        public readonly DateTimeImmutable $since,
        public readonly array $inventory,
        public readonly array $bookedUnits,
        public readonly array $roomUnits,
        public readonly DateRange $checked,
        public readonly ?int $runId = null,
    ) {
    }
}
