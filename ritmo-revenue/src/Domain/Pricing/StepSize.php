<?php

declare(strict_types=1);

namespace Ritmo\Domain\Pricing;

use Ritmo\Domain\Config\RuleSet;

/**
 * How far one pace step moves a price towards its target.
 *
 * Reference: pace.pmax, pace.passo and the tolerance test of pace._plano. The order of the float
 * operations is the reference's and must not change.
 */
final class StepSize
{
    public function __construct(private readonly RuleSet $rules)
    {
    }

    /**
     * The largest step, as a percentage of the current price, at a distance from the night:
     * the band of "pace.max_step", halved when the market anchor is weak.
     */
    public function maxPercent(int $daysOut, bool $weakAnchor = false): float
    {
        return $this->rules->maxStepPercent($daysOut) / ($weakAnchor ? 2 : 1);
    }

    /**
     * The price after one step: "pace.k" times the relative distance to the target, limited to
     * maxPercent() in either direction. A current price of zero cannot step.
     */
    public function next(float $current, float $target, int $daysOut, bool $weakAnchor = false): float
    {
        $limit = $this->rules->maxStepPercent($daysOut) / ($weakAnchor ? 2 : 1) / 100.0;
        $relative = $current != 0.0 ? $this->rules->stepStrength() * ($target - $current) / $current : 0.0;
        $relative = max(-$limit, min($limit, $relative));

        return $current * (1 + $relative);
    }

    /**
     * The direction of a change, or null when it is within "pace.tolerance" of the current price
     * and therefore not worth a step.
     */
    public function direction(float $current, float $new): ?StepDirection
    {
        $tolerance = $this->rules->tolerance();
        if ($new > $current * (1 + $tolerance)) {
            return StepDirection::Up;
        }
        if ($new < $current * (1 - $tolerance)) {
            return StepDirection::Down;
        }

        return null;
    }
}
