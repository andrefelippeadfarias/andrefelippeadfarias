<?php

declare(strict_types=1);

namespace Ritmo\Application\Grid;

use Ritmo\Domain\Model\RoomType;
use Ritmo\Domain\Write\Change\ChangeRecord;
use Ritmo\Domain\Write\ThirdParty\DetectedFreeze;

/**
 * Everything the night detail shows, as plain values. Bookings carry only their PMS id, channel
 * and units: never guest data.
 */
final class NightDetail
{
    /**
     * @param list<array{ref: string, channel: string, units: int}>    $bookings
     * @param list<ChangeRecord>                                          $changes   newest first
     * @param list<array<string, mixed>>                                  $steps     pace steps, newest first
     * @param list<array{run_id: int, status: string, reason: string}>    $decisions newest first
     */
    public function __construct(
        public readonly NightCell $cell,
        public readonly RoomType $room,
        public readonly array $bookings,
        public readonly ?DetectedFreeze $freeze,
        public readonly array $changes,
        public readonly array $steps,
        public readonly array $decisions,
    ) {
    }
}
