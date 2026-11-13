<?php

declare(strict_types=1);

namespace Ritmo\Domain\Operations;

use DateTimeImmutable;
use Ritmo\Domain\Support\Days;

/**
 * What the scheduler needs to know of an earlier run of a property.
 */
final class PastRun
{
    public readonly DateTimeImmutable $runDay;

    /**
     * @param DateTimeImmutable $runDay the property's local date the run worked for (date-only)
     */
    public function __construct(
        public readonly RunKind $kind,
        public readonly DateTimeImmutable $startedAt,
        DateTimeImmutable $runDay,
        public readonly bool $completed,
    ) {
        $this->runDay = Days::normalize($runDay);
    }
}
