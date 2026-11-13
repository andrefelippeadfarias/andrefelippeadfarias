<?php

declare(strict_types=1);

namespace Ritmo\Application\Schedule;

use Ritmo\Domain\Model\Property;
use Ritmo\Domain\Operations\ScheduleConfig;

/**
 * The schedule of each property (from the rule set's schedule section).
 */
interface ScheduleSource
{
    public function schedule(Property $property): ScheduleConfig;
}
