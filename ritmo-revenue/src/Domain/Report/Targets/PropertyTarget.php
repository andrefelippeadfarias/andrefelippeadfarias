<?php

declare(strict_types=1);

namespace Ritmo\Domain\Report\Targets;

/**
 * One property in one month: today's forecast, the realistic and the maximum forecasts, the
 * owner's minimum (typed, else the season's), its RevPAR and how it compares with the realistic.
 */
final class PropertyTarget
{
    public function __construct(
        public readonly string $month,
        public readonly string $propertyId,
        public readonly bool $highSeason,
        public readonly ?int $today,
        public readonly ?int $realistic,
        public readonly ?int $maximum,
        public readonly ?float $minimum,
        public readonly ?int $revparOfMinimum,
        public readonly ?float $minimumVsRealistic,
    ) {
    }
}
