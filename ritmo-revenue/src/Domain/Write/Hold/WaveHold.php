<?php

declare(strict_types=1);

namespace Ritmo\Domain\Write\Hold;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Ritmo\Domain\Plan\PlanItem;

/**
 * The hold a cancellation wave puts on a property: from the first wave until a person confirms
 * the cancellations (or, when configured, some hours after the last one). Immutable.
 */
final class WaveHold
{
    public readonly DateTimeImmutable $since;

    public readonly DateTimeImmutable $lastCancellationAt;

    public readonly ?DateTimeImmutable $releasedAt;

    /**
     * @param array<string, bool> $nights "ROOM|YYYY-MM-DD" => true for the nights of the cancelled bookings
     */
    public function __construct(
        DateTimeImmutable $since,
        DateTimeImmutable $lastCancellationAt,
        public readonly array $nights = [],
        ?DateTimeImmutable $releasedAt = null,
        public readonly ?int $id = null,
    ) {
        if ($lastCancellationAt < $since) {
            throw new InvalidArgumentException('The last cancellation of a wave cannot be before its first.');
        }
        $utc = new DateTimeZone('UTC');
        $this->since = $since->setTimezone($utc);
        $this->lastCancellationAt = $lastCancellationAt->setTimezone($utc);
        $this->releasedAt = $releasedAt?->setTimezone($utc);
    }

    /**
     * The hold of the waves found, or null when there is none.
     *
     * @param list<Wave> $waves
     */
    public static function fromWaves(array $waves): ?self
    {
        if ($waves === []) {
            return null;
        }
        $nights = [];
        foreach ($waves as $wave) {
            foreach ($wave->nights as $room => $keys) {
                foreach ($keys as $key) {
                    $nights[$room . '|' . $key] = true;
                }
            }
        }
        ksort($nights);

        return new self(
            min(array_map(static fn (Wave $w): DateTimeImmutable => $w->firstAt, $waves)),
            max(array_map(static fn (Wave $w): DateTimeImmutable => $w->lastAt, $waves)),
            $nights,
        );
    }

    /**
     * The same hold widened by newer waves (a new wave never shortens it).
     */
    public function with(self $newer): self
    {
        return new self(min($this->since, $newer->since), max($this->lastCancellationAt, $newer->lastCancellationAt), $this->nights + $newer->nights, $this->releasedAt, $this->id);
    }

    public function isActive(DateTimeImmutable $now, ?int $holdHours): bool
    {
        if ($this->releasedAt !== null) {
            return false;
        }

        return $holdHours === null || $now->getTimestamp() < $this->lastCancellationAt->getTimestamp() + $holdHours * 3600;
    }

    public function coversNight(string $room, DateTimeImmutable $night): bool
    {
        return $this->nights[PlanItem::keyOf($room, $night)] ?? false;
    }
}
