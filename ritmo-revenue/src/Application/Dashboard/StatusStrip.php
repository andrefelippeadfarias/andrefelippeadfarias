<?php

declare(strict_types=1);

namespace Ritmo\Application\Dashboard;

use DateTimeImmutable;

/**
 * The status strip of every screen (admin spec 2.2): mode, emergency switch, pause, plans waiting
 * and the last run of the property.
 */
final class StatusStrip
{
    /**
     * @param array{id: int, kind: string, status: string, started_at: DateTimeImmutable, finished_at: DateTimeImmutable|null}|null $lastRun
     */
    public function __construct(
        public readonly string $propertyId,
        public readonly string $mode,
        public readonly bool $emergency,
        public readonly bool $paused,
        public readonly ?DateTimeImmutable $pausedUntil,
        public readonly int $waitingRequests,
        public readonly int $waitingEntries,
        public readonly ?DateTimeImmutable $waitingExpires,
        public readonly ?array $lastRun,
    ) {
    }
}
