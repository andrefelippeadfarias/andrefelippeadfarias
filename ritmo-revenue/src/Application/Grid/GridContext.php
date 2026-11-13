<?php

declare(strict_types=1);

namespace Ritmo\Application\Grid;

use DateTimeImmutable;
use Ritmo\Domain\Calendar\HolidayCalendar;

/**
 * What every cell of one grid read shares: the calendar of the rules, the plan of the last run,
 * the bulk marks and the property's today.
 */
final class GridContext
{
    /**
     * @param array<string, string>     $holidays "YYYY-MM-DD" => period name
     * @param array<string, mixed>|null $run
     */
    public function __construct(
        public readonly HolidayCalendar $calendar,
        public readonly array $holidays,
        public readonly PlanIndex $plan,
        public readonly ?array $run,
        public readonly NightMarks $marks,
        public readonly DateTimeImmutable $today,
    ) {
    }
}
