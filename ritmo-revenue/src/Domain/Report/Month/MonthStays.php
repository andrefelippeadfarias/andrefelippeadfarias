<?php

declare(strict_types=1);

namespace Ritmo\Domain\Report\Month;

use Ritmo\Domain\Support\Num;

/**
 * The stays of one property in one month: realised (nights before today) and on the books (today
 * to the month's end), the month's totals against the owner's minimum, the room nights still free
 * and the average daily rate the free ones need to reach the minimum.
 *
 * Reference: desempenho_mes.calcular, "estadias".
 */
final class MonthStays
{
    public function __construct(
        public readonly string $propertyId,
        public readonly int $capacity,
        public readonly int $daysPast,
        public readonly int $realisedNights,
        public readonly float $realisedRevenue,
        public readonly ?float $realisedOccupancy,
        public readonly ?float $realisedAdr,
        public readonly ?float $realisedRevpar,
        public readonly int $daysAhead,
        public readonly int $aheadNights,
        public readonly float $aheadRevenue,
        public readonly ?float $aheadOccupancy,
        public readonly int $nights,
        public readonly float $revenue,
        public readonly ?float $occupancy,
        public readonly ?float $adr,
        public readonly ?float $minimum,
        public readonly ?float $gapToMinimum,
        public readonly int $freeNights,
        public readonly ?float $adrNeeded,
    ) {
    }

    /**
     * @param array{nights: int, revenue: float} $realised
     * @param array{nights: int, revenue: float} $ahead
     */
    public static function of(string $propertyId, int $capacity, int $days, int $daysPast, array $realised, array $ahead, MonthTarget $target): self
    {
        $daysAhead = $days - $daysPast;
        $nights = $realised['nights'] + $ahead['nights'];
        $revenue = $realised['revenue'] + $ahead['revenue'];
        $minimum = $target->minimumSet();
        $free = $capacity * $daysAhead - $ahead['nights'];

        return new self(
            $propertyId,
            $capacity,
            $daysPast,
            $realised['nights'],
            Num::roundHalfEven($realised['revenue'], 2),
            self::ratio($realised['nights'], $capacity * $daysPast, 3),
            $realised['nights'] > 0 ? Num::roundHalfEven($realised['revenue'] / $realised['nights'], 2) : null,
            self::ratio($realised['revenue'], $capacity * $daysPast, 2),
            $daysAhead,
            $ahead['nights'],
            Num::roundHalfEven($ahead['revenue'], 2),
            self::ratio($ahead['nights'], $capacity * $daysAhead, 3),
            $nights,
            Num::roundHalfEven($revenue, 2),
            self::ratio($nights, $capacity * $days, 3),
            $nights > 0 ? Num::roundHalfEven($revenue / $nights, 2) : null,
            $target->minimum,
            $minimum !== null ? Num::roundHalfEven($minimum - $revenue, 2) : null,
            $free,
            $minimum !== null && $free > 0 && $minimum > $revenue ? Num::roundHalfEven(($minimum - $revenue) / $free, 2) : null,
        );
    }

    /**
     * The month's revenue over the minimum (null without a minimum).
     */
    public function achieved(int $decimals): ?float
    {
        $minimum = $this->minimum !== null && $this->minimum > 0 ? $this->minimum : null;

        return $minimum === null ? null : Num::roundHalfEven($this->revenue / $minimum, $decimals);
    }

    private static function ratio(int|float $value, int $over, int $decimals): ?float
    {
        return $over > 0 ? Num::roundHalfEven($value / $over, $decimals) : null;
    }
}
