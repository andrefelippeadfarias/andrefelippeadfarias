<?php

declare(strict_types=1);

namespace Ritmo\Domain\Pricing;

use InvalidArgumentException;
use Ritmo\Domain\Config\RuleSet;
use Ritmo\Domain\Model\NightType;
use Ritmo\Domain\Model\PaceStatus;

/**
 * Classifies a night as ahead of, on, behind or very behind its expected sales curve.
 *
 * Reference: pace.status_da_noite.
 */
final class PaceMeter
{
    private readonly PaceCurve $curve;

    public function __construct(private readonly RuleSet $rules, ?PaceCurve $curve = null)
    {
        $this->curve = $curve ?? new PaceCurve($rules);
    }

    /**
     * The checks run in this order:
     *
     * 1. nothing sold within "pace.zero_sold_days" of the night: very behind;
     * 2. sold at least "pace.very_behind" points under the curve: very behind;
     * 3. at least "pace.band" points under: behind;
     * 4. at least "pace.band" points over: ahead;
     * 5. otherwise on pace.
     *
     * @param int $sold  units sold (above $units when overbooked)
     * @param int $units units on sale; zero counts as nothing sold
     *
     * @throws InvalidArgumentException on a negative count
     */
    public function measure(NightType $type, int $daysOut, int $sold, int $units): PaceReading
    {
        if ($units < 0) {
            throw new InvalidArgumentException('Units cannot be negative.');
        }

        $soldPercent = $units > 0 ? 100.0 * $sold / $units : 0.0;
        $expected = $this->curve->expected($type, $daysOut);
        $gap = $soldPercent - $expected;

        $status = match (true) {
            $sold <= 0 && $daysOut <= $this->rules->zeroSoldDays($type) => PaceStatus::VeryBehind,
            $gap <= -$this->rules->paceVeryBehind() => PaceStatus::VeryBehind,
            $gap <= -$this->rules->paceBand() => PaceStatus::Behind,
            $gap >= $this->rules->paceBand() => PaceStatus::Ahead,
            default => PaceStatus::OnPace,
        };

        return new PaceReading($status, $soldPercent, $expected);
    }
}
