<?php

declare(strict_types=1);

namespace Ritmo\Domain\Relations\Coherence;

use DateTimeImmutable;
use Ritmo\Domain\Relations\RelationGraph;
use Ritmo\Domain\Relations\RelationStage;
use Ritmo\Domain\Relations\ScanMode;
use Ritmo\Domain\Support\Days;

/**
 * The nights the coherence stage looks at.
 *
 * Always the nights the engine touched (a decided price in this run). A full run adds, for every
 * relation of scan "horizon", the nights of its target's calendar from today to the scan horizon;
 * a light run adds the nights of its filter instead.
 */
final class NightSet
{
    /**
     * @return list<DateTimeImmutable> sorted, without repeats
     */
    public static function of(RelationGraph $graph, NightFacts $facts, DateTimeImmutable $today, int $horizonDays): array
    {
        $nights = [];
        foreach ($facts->decidedNights() as $night) {
            $nights[Days::key($night)] = $night;
        }
        if ($facts->isLightRun()) {
            foreach ($facts->filterNights() as $night) {
                $nights[Days::key($night)] = $night;
            }
        } else {
            foreach ($graph->orderedFor(RelationStage::Coherence) as $relation) {
                if ($relation->scan !== ScanMode::Horizon) {
                    continue;
                }
                foreach ($facts->calendarNights($relation->target) as $night) {
                    $daysOut = Days::diff($today, $night);
                    if ($daysOut >= 0 && $daysOut <= $horizonDays) {
                        $nights[Days::key($night)] = $night;
                    }
                }
            }
        }
        ksort($nights);

        return array_values($nights);
    }
}
