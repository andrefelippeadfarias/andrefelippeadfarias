<?php

declare(strict_types=1);

namespace Ritmo\Application\Run;

use RuntimeException;

/**
 * The bookings of a property could not be read: the run decides and writes nothing (operations
 * spec O21, "watch blind").
 */
final class BookingsUnwatched extends RuntimeException
{
}
