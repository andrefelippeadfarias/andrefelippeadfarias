<?php

declare(strict_types=1);

namespace Ritmo\Domain\Market;

use DateTimeInterface;
use Ritmo\Domain\Config\RuleSet;
use Ritmo\Domain\Model\AnchorOrigin;
use Ritmo\Domain\Model\MarketAnchor;
use Ritmo\Domain\Support\Days;
use Ritmo\Domain\Support\Num;

/**
 * Reads the market anchors: the anchor that applies to a night and the two conversions between
 * a percentile and a public price.
 *
 * Reference: ancora_mercado.ancora_da_noite, valor_percentil and percentil_de. The order of the
 * float operations is the reference's and must not change.
 */
final class AnchorLookup
{
    /** @var array<string, MarketAnchor> */
    private readonly array $anchors;

    /** @var array<string, array<string, MarketAnchor>> night type => 'YYYY-MM' => fallback */
    private readonly array $fallbacks;

    /**
     * @param array<string, MarketAnchor> $anchors the anchors held, 'YYYY-MM-DD' => anchor
     */
    public function __construct(
        private readonly RuleSet $rules,
        array $anchors,
        ?AnchorBuilder $builder = null,
    ) {
        ksort($anchors);
        $this->anchors = $anchors;
        $this->fallbacks = ($builder ?? new AnchorBuilder($rules))->monthlyFallbacks($anchors);
    }

    /**
     * The anchor of a night: its own; otherwise the fallback of its night type in its month;
     * otherwise the fallback of its night type in the closest month (by the distance between
     * the night and the 15th of the month; the earlier month on a tie). Null when the night type
     * has no anchor at all.
     *
     * A fallback is returned dated as the night asked, with n = 0 and origin "fallback".
     */
    public function forNight(DateTimeInterface $night): ?MarketAnchor
    {
        $key = Days::key($night);
        if (isset($this->anchors[$key])) {
            return $this->anchors[$key];
        }

        $byMonth = $this->fallbacks[$this->rules->nightType($night)->value] ?? [];
        if ($byMonth === []) {
            return null;
        }

        $fallback = $byMonth[substr($key, 0, 7)] ?? null;
        if ($fallback === null) {
            $closest = null;
            foreach ($byMonth as $candidate) {
                $distance = abs(Days::diff($night, $candidate->date));
                if ($closest === null || $distance < $closest) {
                    $closest = $distance;
                    $fallback = $candidate;
                }
            }
        }

        return new MarketAnchor(
            Days::normalize($night),
            $fallback->p25,
            $fallback->p50,
            $fallback->p75,
            $fallback->p90,
            $fallback->max,
            0,
            $fallback->capturedAt,
            AnchorOrigin::Fallback,
        );
    }

    /**
     * The public price at a percentile: linear between the known points (25, 50, 75, 90, 100),
     * proportional to p25 below 25 and following the line of the maximum above 100.
     */
    public static function valueAt(MarketAnchor $anchor, float $percentile): float
    {
        if ($percentile <= 25) {
            return $anchor->p25 * $percentile / 25.0;
        }

        $points = [[25.0, $anchor->p25], [50.0, $anchor->p50], [75.0, $anchor->p75], [90.0, $anchor->p90], [100.0, $anchor->max]];
        for ($i = 1, $count = count($points); $i < $count; $i++) {
            [$x0, $y0] = $points[$i - 1];
            [$x1, $y1] = $points[$i];
            if ($percentile <= $x1) {
                return $y0 + ($y1 - $y0) * ($percentile - $x0) / ($x1 - $x0);
            }
        }

        return $anchor->max * (1 + ($percentile - 100) / 100.0);
    }

    /**
     * The percentile (0 to 100, one decimal; above 100 for a price over the maximum) where a
     * public price falls in the anchor.
     */
    public static function percentileOf(MarketAnchor $anchor, float $publicPrice): float
    {
        if ($publicPrice <= $anchor->p25) {
            return $anchor->p25 > 0.0 ? Num::roundHalfEven(25.0 * $publicPrice / $anchor->p25, 1) : 0.0;
        }

        $points = [[$anchor->p25, 25.0], [$anchor->p50, 50.0], [$anchor->p75, 75.0], [$anchor->p90, 90.0], [$anchor->max, 100.0]];
        for ($i = 1, $count = count($points); $i < $count; $i++) {
            [$x0, $y0] = $points[$i - 1];
            [$x1, $y1] = $points[$i];
            if ($publicPrice <= $x1) {
                return $x1 > $x0 ? Num::roundHalfEven($y0 + ($y1 - $y0) * ($publicPrice - $x0) / ($x1 - $x0), 1) : $y1;
            }
        }

        if ($anchor->max <= 0.0) {
            // An anchor without a positive price cannot rank a price: anything above it is at the top.
            return 100.0;
        }

        return Num::roundHalfEven(100.0 + 10.0 * ($publicPrice - $anchor->max) / $anchor->max, 1);
    }
}
