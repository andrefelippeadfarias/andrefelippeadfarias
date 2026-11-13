<?php

declare(strict_types=1);

namespace Ritmo\Domain\Market\Series;

/**
 * Keeps, per competitor, stay and room basis, only the newest capture (ties: the higher
 * sequence, that is the later import and then the later line). Divergence MK-X1: the reference
 * keeps the first sweep file's version of a whole stay.
 */
final class NewestSamples
{
    private function __construct()
    {
    }

    /**
     * @param list<StaySample> $samples
     *
     * @return list<StaySample> in the order of the samples kept
     */
    public static function of(array $samples): array
    {
        $kept = [];
        foreach ($samples as $sample) {
            $key = $sample->competitorId . '|' . $sample->stayKey() . '|' . $sample->basis->value;
            $current = $kept[$key] ?? null;
            if ($current === null || self::newer($sample, $current)) {
                $kept[$key] = $sample;
            }
        }

        return array_values($kept);
    }

    private static function newer(StaySample $a, StaySample $b): bool
    {
        return $a->capturedAt > $b->capturedAt || ($a->capturedAt == $b->capturedAt && $a->sequence > $b->sequence);
    }
}
