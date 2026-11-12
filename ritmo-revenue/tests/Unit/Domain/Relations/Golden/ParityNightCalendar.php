<?php

declare(strict_types=1);

namespace Ritmo\Tests\Unit\Domain\Relations\Golden;

use DateTimeImmutable;
use Ritmo\Domain\Model\NightType;
use Ritmo\Domain\Relations\Port\NightCalendar;
use Ritmo\Tests\Support\Fixtures;

/**
 * Night types from the parity rule set: the plugin's own calendar, never the recorded one.
 */
final class ParityNightCalendar implements NightCalendar
{
    public function nightType(DateTimeImmutable $night): NightType
    {
        return Fixtures::parityRules()->nightType($night);
    }
}
