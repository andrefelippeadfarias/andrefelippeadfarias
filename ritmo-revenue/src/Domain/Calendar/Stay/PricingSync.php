<?php

declare(strict_types=1);

namespace Ritmo\Domain\Calendar\Stay;

/**
 * For which rooms the pricing system pushes each stay field to the PMS. "*" stands for every room.
 * A field counts only when the pricing connector can carry it (StaySyncCapability).
 */
final class PricingSync
{
    public const EVERY_ROOM = '*';

    /**
     * @param list<string> $minStayRooms
     * @param list<string> $maxStayRooms
     * @param list<string> $arrivalDepartureRooms
     */
    public function __construct(
        public readonly array $minStayRooms = [],
        public readonly array $maxStayRooms = [],
        public readonly array $arrivalDepartureRooms = [],
    ) {
    }

    public function pushes(StaySyncField $field, string $roomCode, StaySyncCapability $capability): bool
    {
        $rooms = match ($field) {
            StaySyncField::MinStay => $this->minStayRooms,
            StaySyncField::MaxStay => $this->maxStayRooms,
            StaySyncField::ArrivalDeparture => $this->arrivalDepartureRooms,
        };

        return $capability->supports($field)
            && (in_array(self::EVERY_ROOM, $rooms, true) || in_array($roomCode, $rooms, true));
    }
}
