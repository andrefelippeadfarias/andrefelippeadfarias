<?php

declare(strict_types=1);

namespace Ritmo\Domain\Market\Series;

/**
 * Where a price sits in the market: its percentile among competitor values, and its place
 * among the market bands.
 *
 * Reference: faixas_dono.percentil_de and faixas_dono.posicao.
 */
final class PricePosition
{
    private function __construct()
    {
    }

    /**
     * The share of the values below the price plus half of the ties, times 100; null when there
     * is no value.
     *
     * @param list<int|float> $values
     */
    public static function percentile(float $price, array $values): ?float
    {
        if ($values === []) {
            return null;
        }
        $below = 0;
        $equal = 0;
        foreach ($values as $value) {
            if ($value < $price) {
                ++$below;
            } elseif ($value == $price) {
                ++$equal;
            }
        }

        return 100.0 * ($below + $equal / 2) / count($values);
    }

    /**
     * The band the amount is near (within tolerance x the band's value), or the two bands it sits
     * between, or below the lowest, or above the highest.
     *
     * @param array<string, float> $bands label => value, in the order of the bands
     * @param float                $tolerance 0.05 = within 5% of a band's value
     */
    public static function band(float $amount, array $bands, float $tolerance): BandPosition
    {
        $items = [];
        foreach ($bands as $label => $value) {
            $items[] = [(string) $label, $value];
        }
        usort($items, static fn (array $a, array $b): int => $a[1] <=> $b[1]);
        foreach ($items as [$label, $value]) {
            if (abs($amount - $value) <= $value * $tolerance) {
                return new BandPosition(BandPosition::NEAR, [$label]);
            }
        }
        if ($items === []) {
            return new BandPosition(BandPosition::NONE, []);
        }
        if ($amount < $items[0][1]) {
            return new BandPosition(BandPosition::BELOW, [$items[0][0]]);
        }
        $last = $items[count($items) - 1];
        if ($amount > $last[1]) {
            return new BandPosition(BandPosition::ABOVE, [$last[0]]);
        }
        for ($i = 0; $i + 1 < count($items); ++$i) {
            if ($items[$i][1] < $amount && $amount < $items[$i + 1][1]) {
                return new BandPosition(BandPosition::BETWEEN, [$items[$i][0], $items[$i + 1][0]]);
            }
        }

        return new BandPosition(BandPosition::NONE, []);
    }
}
