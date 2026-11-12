<?php

declare(strict_types=1);

namespace Ritmo\Domain\Relations\Neutral;

use DateTimeImmutable;
use Ritmo\Domain\Config\RuleSet;
use Ritmo\Domain\Model\NightType;
use Ritmo\Domain\Relations\Port\NightCalendar;

/**
 * Night types from the rule set: holiday periods, then the configured weekend nights.
 */
final class RuleSetNightCalendar implements NightCalendar
{
    public function __construct(private readonly RuleSet $rules)
    {
    }

    public function nightType(DateTimeImmutable $night): NightType
    {
        return $this->rules->nightType($night);
    }
}
