<?php

declare(strict_types=1);

namespace Ritmo\Application\Run;

use Ritmo\Application\Schedule\ScheduleSource;
use Ritmo\Domain\Config\RuleSet;
use Ritmo\Domain\Model\Property;
use Ritmo\Domain\Operations\ScheduleConfig;
use Ritmo\Infrastructure\Db\Repository\RuleSetRepository;

/**
 * The schedule of every property from the rule set in force (section "schedule"; the neutral
 * rules: the daily full run only).
 */
final class CurrentSchedule implements ScheduleSource
{
    public function __construct(private readonly RuleSetRepository $ruleSets)
    {
    }

    public function schedule(Property $property): ScheduleConfig
    {
        return ($this->ruleSets->current() ?? RuleSet::defaults())->schedule();
    }
}
