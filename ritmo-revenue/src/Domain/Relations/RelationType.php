<?php

declare(strict_types=1);

namespace Ritmo\Domain\Relations;

/**
 * The kinds of relation between room types of a property.
 */
enum RelationType: string
{
    /** The target never below a reference, with an optional margin. */
    case AtLeast = 'at_least';

    /** The target between two references (part of the way), or between two percents of one. */
    case Between = 'between';

    /** The target above the dearest room of a set, by a percent that depends on the night type. */
    case PremiumOverMax = 'premium_over_max';

    /** The target between two percents of the cheapest free room of a set. */
    case DiscountBand = 'discount_band';

    /** When enough units of a set are sold, the target changes rule. */
    case OccupancySwitch = 'occupancy_switch';

    /**
     * Stages a relation of this type may run in.
     *
     * @return list<RelationStage>
     */
    public function allowedStages(): array
    {
        return match ($this) {
            self::AtLeast, self::Between, self::PremiumOverMax => [RelationStage::Strategy, RelationStage::Coherence, RelationStage::Audit],
            self::DiscountBand, self::OccupancySwitch => [RelationStage::Coherence, RelationStage::Validate],
        };
    }
}
