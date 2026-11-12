<?php

declare(strict_types=1);

namespace Ritmo\Domain\Report;

/**
 * Occupancy of one window of nights (the next 7, 15 ... nights) against its goal.
 */
final class WindowOccupancy
{
    /**
     * @param int        $days     nights of the window, starting today
     * @param int        $sold     unit nights sold in the window
     * @param int        $capacity unit nights on sale in the window (only the nights reported)
     * @param float|null $percent  sold share with one decimal; null when the window has no capacity
     * @param float      $goal     percent sold the window should reach
     */
    public function __construct(
        public readonly int $days,
        public readonly int $sold,
        public readonly int $capacity,
        public readonly ?float $percent,
        public readonly float $goal,
    ) {
    }

    /**
     * Null when the window has no capacity to measure.
     */
    public function meetsGoal(): ?bool
    {
        return $this->percent === null ? null : $this->percent >= $this->goal;
    }
}
