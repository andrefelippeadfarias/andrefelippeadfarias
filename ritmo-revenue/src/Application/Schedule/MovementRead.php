<?php

declare(strict_types=1);

namespace Ritmo\Application\Schedule;

use Ritmo\Contracts\Exception\ConnectorException;
use Ritmo\Domain\Model\BookingRecord;
use Ritmo\Domain\Operations\MovementBatch;
use Ritmo\Domain\Operations\MovementWindow;

/**
 * The result of one movement read: the bookings read (the one list the watch also uses), the
 * window it covered and the movement found; or the failure that left the bookings unwatched.
 */
final class MovementRead
{
    /**
     * @param list<BookingRecord> $bookings
     */
    private function __construct(
        public readonly MovementWindow $window,
        public readonly array $bookings,
        public readonly ?MovementBatch $batch,
        public readonly ?ConnectorException $failure,
    ) {
    }

    /**
     * @param list<BookingRecord> $bookings
     */
    public static function read(MovementWindow $window, array $bookings, MovementBatch $batch): self
    {
        return new self($window, $bookings, $batch, null);
    }

    public static function failed(MovementWindow $window, ConnectorException $failure): self
    {
        return new self($window, [], null, $failure);
    }

    public function succeeded(): bool
    {
        return $this->failure === null;
    }
}
