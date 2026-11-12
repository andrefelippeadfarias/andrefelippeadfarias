<?php

declare(strict_types=1);

namespace Ritmo\Domain\Pricing;

use Ritmo\Domain\Config\RuleSet;
use Ritmo\Domain\Model\PaceStatus;
use Ritmo\Domain\Model\Property;

/**
 * The market percentile a night should aim at. Each property has a tier and each tier its own
 * table: one target per pace status, plus the target of a night that is ahead and close.
 *
 * Reference: pace.percentil_alvo.
 */
final class TargetPercentile
{
    public function __construct(private readonly RuleSet $rules)
    {
    }

    /**
     * A property whose tier has no table uses the "standard" table.
     */
    public function forNight(Property $property, PaceStatus $status, int $daysOut): float
    {
        return $this->rules->targetPercentile($property->tier, $status, $daysOut);
    }

    /**
     * True when the night uses the "ahead, close to the night" target.
     */
    public function isAheadNear(PaceStatus $status, int $daysOut): bool
    {
        return $status === PaceStatus::Ahead && $daysOut <= $this->rules->aheadNearDays();
    }
}
