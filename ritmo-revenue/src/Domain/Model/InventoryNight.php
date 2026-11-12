<?php

declare(strict_types=1);

namespace Ritmo\Domain\Model;

use DateTimeImmutable;
use InvalidArgumentException;
use Ritmo\Domain\Support\Days;

/**
 * One night of a room in the PMS inventory calendar.
 */
final class InventoryNight
{
    public readonly DateTimeImmutable $date;

    /**
     * @param int        $available units still for sale; negative when the night is overbooked by that many units
     * @param int|null   $minStay   null when the PMS has no value for the night
     * @param int|null   $maxStay   null when the PMS has no value for the night
     * @param float|null $pmsPrice  price held by the PMS for the night; null when not read
     *
     * @throws InvalidArgumentException on invalid stay lengths or a negative price
     */
    public function __construct(
        DateTimeImmutable $date,
        public readonly int $available,
        public readonly ?int $minStay = null,
        public readonly ?int $maxStay = null,
        public readonly Restriction $restriction = Restriction::None,
        public readonly ?float $pmsPrice = null,
    ) {
        if (($minStay !== null && $minStay < 1) || ($maxStay !== null && $maxStay < 1)) {
            throw new InvalidArgumentException('Stay lengths must be at least one night.');
        }
        if ($pmsPrice !== null && (!is_finite($pmsPrice) || $pmsPrice < 0)) {
            throw new InvalidArgumentException('The PMS price cannot be negative.');
        }
        $this->date = Days::normalize($date);
    }
}
