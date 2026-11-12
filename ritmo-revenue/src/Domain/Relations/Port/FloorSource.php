<?php

declare(strict_types=1);

namespace Ritmo\Domain\Relations\Port;

use DateTimeImmutable;
use Ritmo\Domain\Relations\Relation;

/**
 * The floors of a room-night in the sent plan, as the floor rules and the owner's orders set them.
 */
interface FloorSource
{
    /** The floor the room has tonight, and whether an owner floor order set it. */
    public function inForce(string $room, DateTimeImmutable $night): FloorInForce;

    /**
     * The floor to use when an ORDER floor is above a band: the lowest value the order allows when
     * it releases going below the standard floor (never below the safety limit), else the
     * standard floor.
     */
    public function lowestAllowed(string $room, DateTimeImmutable $night): float;

    /** The floor of the target of a between relation and the hints the ladder adds to it. */
    public function betweenHints(Relation $relation, string $room, DateTimeImmutable $night): BetweenHints;

    /** The floor an occupancy switch never breaks: the safety limit, and the holiday floor on a holiday. */
    public function switchFloor(string $room, DateTimeImmutable $night): float;

    /**
     * The floor the audit requires: the owner's and holiday floors, plus the lead-time market
     * floor only when $today is given. Null when the room has no rule floor.
     */
    public function ruleFloor(string $room, DateTimeImmutable $night, ?DateTimeImmutable $today): ?float;

    /** An owner exception to the safety limit in force for the room-night, or null. */
    public function safetyException(string $room, DateTimeImmutable $night): ?float;
}
