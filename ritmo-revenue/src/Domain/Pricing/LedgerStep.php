<?php

declare(strict_types=1);

namespace Ritmo\Domain\Pricing;

use DateTimeImmutable;
use DateTimeZone;

/**
 * The last pace step recorded for a room night, as the step ledger holds it.
 */
final class LedgerStep
{
    public readonly DateTimeImmutable $recordedAt;

    /**
     * @param DateTimeImmutable $recordedAt     instant the step was written
     * @param bool              $reachedChannel true once the pricing system pushed the step to the sales channels
     */
    public function __construct(
        public readonly StepDirection $direction,
        DateTimeImmutable $recordedAt,
        public readonly bool $reachedChannel,
    ) {
        $this->recordedAt = $recordedAt->setTimezone(new DateTimeZone('UTC'));
    }
}
