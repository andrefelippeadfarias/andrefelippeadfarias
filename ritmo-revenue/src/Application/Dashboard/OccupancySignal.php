<?php

declare(strict_types=1);

namespace Ritmo\Application\Dashboard;

/**
 * The traffic light of an occupancy window against its goal.
 */
final class OccupancySignal
{
    public const GREEN = 'green';

    public const AMBER = 'amber';

    public const RED = 'red';

    /** No night of the window was reported by the PMS. */
    public const UNKNOWN = 'unknown';

    /** Up to this many percentage points under the goal is amber; further under is red. */
    public const AMBER_MARGIN_POINTS = 10.0;

    private function __construct()
    {
    }

    public static function classify(?float $percent, float $goal): string
    {
        return match (true) {
            $percent === null => self::UNKNOWN,
            $percent >= $goal => self::GREEN,
            $percent >= $goal - self::AMBER_MARGIN_POINTS => self::AMBER,
            default => self::RED,
        };
    }
}
