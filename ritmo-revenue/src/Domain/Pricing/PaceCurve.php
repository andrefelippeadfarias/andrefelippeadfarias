<?php

declare(strict_types=1);

namespace Ritmo\Domain\Pricing;

use Ritmo\Domain\Config\RuleSet;
use Ritmo\Domain\Model\NightType;

/**
 * The expected sales curve: the share of a room that should be sold a number of days before the
 * night, by night type.
 *
 * Reference: pace.curva. The order of the float operations is the reference's and must not change.
 */
final class PaceCurve
{
    public function __construct(private readonly RuleSet $rules)
    {
    }

    /**
     * Percent sold expected $daysOut days before the night: linear between the configured points,
     * the farthest point from there outwards, the point of the day itself for a night already past.
     */
    public function expected(NightType $type, int $daysOut): float
    {
        $points = [];
        foreach ($this->rules->paceCurve($type) as $days => $percent) {
            $points[] = [(int) $days, (float) $percent];
        }

        if ($daysOut >= $points[0][0]) {
            return $points[0][1];
        }
        for ($i = 1, $count = count($points); $i < $count; $i++) {
            [$farDays, $farPercent] = $points[$i - 1];
            [$nearDays, $nearPercent] = $points[$i];
            if ($nearDays <= $daysOut && $daysOut <= $farDays) {
                return $farDays > $nearDays
                    ? $nearPercent + ($farPercent - $nearPercent) * ($daysOut - $nearDays) / ($farDays - $nearDays)
                    : $nearPercent;
            }
        }

        return $points[count($points) - 1][1];
    }
}
