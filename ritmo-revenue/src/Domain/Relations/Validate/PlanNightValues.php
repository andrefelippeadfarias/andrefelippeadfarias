<?php

declare(strict_types=1);

namespace Ritmo\Domain\Relations\Validate;

/**
 * What the plan under validation sets for its room-nights, keyed by "ROOM|YYYY-MM-DD".
 */
final class PlanNightValues
{
    /**
     * @param array<string, float>      $planned       planned floor or fixed price
     * @param array<string, float>      $ceilings      effective ceiling of the item
     * @param list<string>              $percentFloors keys whose planned value is a floor sent as a percent
     * @param array<string, string>     $orderRefs     order cited by the item
     * @param array<string, FreshNight> $fresh         the validator's fresh calendar
     */
    public function __construct(
        public readonly array $planned,
        public readonly array $ceilings = [],
        public readonly array $percentFloors = [],
        public readonly array $orderRefs = [],
        public readonly array $fresh = [],
    ) {
    }
}
