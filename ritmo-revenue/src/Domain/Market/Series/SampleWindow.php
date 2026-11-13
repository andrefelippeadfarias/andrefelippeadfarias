<?php

declare(strict_types=1);

namespace Ritmo\Domain\Market\Series;

use DateTimeImmutable;
use Ritmo\Domain\Support\Days;

/**
 * Which samples count: stays that have not started (check-in on or after the property's today)
 * and prices captured at or after an instant (now minus market.max_sample_age_days).
 */
final class SampleWindow
{
    public readonly DateTimeImmutable $today;

    public function __construct(DateTimeImmutable $today, public readonly DateTimeImmutable $capturedSince)
    {
        $this->today = Days::normalize($today);
    }

    public function admits(StaySample $sample): bool
    {
        return $sample->checkIn >= $this->today && $sample->capturedAt >= $this->capturedSince;
    }
}
