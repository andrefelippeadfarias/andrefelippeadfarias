<?php

declare(strict_types=1);

namespace Ritmo\Domain\Pricing\Cadence;

use Ritmo\Domain\Plan\PlanItem;

/**
 * The items a plan keeps and the ones the no-effect filter dropped, by reason.
 */
final class NoEffectResult
{
    /**
     * @param list<PlanItem> $kept
     * @param list<PlanItem> $noEffect     floor and ceiling equal to what is live
     * @param list<PlanItem> $roundingOnly only a rounding step away from what is live
     */
    public function __construct(
        public readonly array $kept,
        public readonly array $noEffect,
        public readonly array $roundingOnly,
    ) {
    }
}
