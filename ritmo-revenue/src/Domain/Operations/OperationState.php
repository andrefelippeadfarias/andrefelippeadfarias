<?php

declare(strict_types=1);

namespace Ritmo\Domain\Operations;

use DateTimeImmutable;

/**
 * The operation state of one property: its pause and the cursors of the scheduler and of the
 * movement read.
 */
final class OperationState
{
    /**
     * @param DateTimeImmutable|null $movementCursor       instant just before the last successful movement read
     * @param DateTimeImmutable|null $slotsConsideredUntil instant up to which the slots were materialised
     */
    public function __construct(
        public readonly string $propertyId,
        public readonly bool $paused = false,
        public readonly ?DateTimeImmutable $pausedUntil = null,
        public readonly ?int $pausedBy = null,
        public readonly string $pauseReason = '',
        public readonly ?DateTimeImmutable $movementCursor = null,
        public readonly ?DateTimeImmutable $slotsConsideredUntil = null,
    ) {
    }

    public function isPausedAt(DateTimeImmutable $now): bool
    {
        return $this->paused && ($this->pausedUntil === null || $this->pausedUntil > $now);
    }

    /**
     * True when a pause with an end has reached it and should be lifted.
     */
    public function pauseEndedAt(DateTimeImmutable $now): bool
    {
        return $this->paused && $this->pausedUntil !== null && $this->pausedUntil <= $now;
    }
}
