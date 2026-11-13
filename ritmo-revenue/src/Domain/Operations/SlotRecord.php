<?php

declare(strict_types=1);

namespace Ritmo\Domain\Operations;

use DateTimeImmutable;

/**
 * A stored slot occurrence and what became of it.
 */
final class SlotRecord
{
    public function __construct(
        public readonly int $id,
        public readonly string $propertyId,
        public readonly Slot $slot,
        public readonly SlotStatus $status,
        public readonly bool $promoted = false,
        public readonly ?int $runId = null,
        public readonly ?DateTimeImmutable $startedAt = null,
        public readonly ?DateTimeImmutable $finishedAt = null,
        public readonly ?int $delaySeconds = null,
        public readonly string $note = '',
    ) {
    }
}
