<?php

declare(strict_types=1);

namespace Ritmo\Domain\Alerts;

use DateTimeImmutable;

/**
 * An alert as stored, with its lifecycle facts.
 */
final class AlertRecord
{
    /**
     * @param array<string, mixed> $context
     */
    public function __construct(
        public readonly int $id,
        public readonly string $code,
        public readonly AlertSeverity $severity,
        public readonly AlertStatus $status,
        public readonly string $propertyId,
        public readonly string $roomCode,
        public readonly ?DateTimeImmutable $night,
        public readonly string $message,
        public readonly array $context,
        public readonly string $dedupeKey,
        public readonly ?int $runId,
        public readonly DateTimeImmutable $createdAt,
        public readonly DateTimeImmutable $updatedAt,
        public readonly ?DateTimeImmutable $resolvedAt = null,
        public readonly ?DateTimeImmutable $evidenceAt = null,
        public readonly ?DateTimeImmutable $notifiedAt = null,
        public readonly ?DateTimeImmutable $digestedAt = null,
        public readonly int $occurrences = 1,
    ) {
    }

    /**
     * Codes outside the catalog (raised by an older version) behave as events: only a person ends them.
     */
    public function lifecycle(): AlertLifecycle
    {
        return AlertCode::tryFrom($this->code)?->lifecycle() ?? AlertLifecycle::Event;
    }
}
