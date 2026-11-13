<?php

declare(strict_types=1);

namespace Ritmo\Domain\Write\Confirm;

/**
 * Whether a written date rule is in force in the recalculated calendar. A price rule only counts
 * as confirmed when the night shows the date rule in force (the pricing system's override flag)
 * and its floor is the written one within max(1, 1.5% of the floor). A rule that sets no price
 * (only a stay or the arrival days) is not judged here.
 *
 * Reference: ler_recalculo.regra_inativa (a rule with a lead-time expiry stops counting N days
 * before the night and the calendar silently shows the listing's own floor).
 */
final class RuleLiveness
{
    private function __construct()
    {
    }

    /**
     * @param bool       $priceRule         the written rule sets a floor or a ceiling
     * @param float|null $writtenFloor      the written floor as an amount, when it sets one
     * @param bool       $inForce           the recalculated night applies a date rule
     * @param float|null $recalculatedFloor the floor of the recalculated night (missing reads 0)
     */
    public static function check(bool $priceRule, ?float $writtenFloor, bool $inForce, ?float $recalculatedFloor): ?LivenessIssue
    {
        if (!$priceRule) {
            return null;
        }
        if (!$inForce) {
            return LivenessIssue::ExpiredOrOutOfReach;
        }
        if ($writtenFloor !== null && abs(($recalculatedFloor ?? 0.0) - $writtenFloor) > max(1.0, 0.015 * $writtenFloor)) {
            return LivenessIssue::FloorDiffers;
        }

        return null;
    }
}
