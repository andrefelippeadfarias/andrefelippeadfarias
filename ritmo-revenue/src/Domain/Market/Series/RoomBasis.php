<?php

declare(strict_types=1);

namespace Ritmo\Domain\Market\Series;

/**
 * Which room of a competitor a price is for: the cheapest room the channel shows for the stay,
 * or a room comparable to the property's rooms (with the same amenity, for example).
 */
enum RoomBasis: string
{
    case Cheapest = 'cheapest';
    case Comparable = 'comparable';
}
