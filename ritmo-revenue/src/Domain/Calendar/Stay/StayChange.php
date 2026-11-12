<?php

declare(strict_types=1);

namespace Ritmo\Domain\Calendar\Stay;

use DateTimeImmutable;
use InvalidArgumentException;
use Ritmo\Domain\Support\Days;

/**
 * A minimum and/or maximum stay to write on one night of a room, ready for the milestone that
 * writes (nothing here sends it).
 *
 * The values read when the change was planned (minStayBefore, maxStayBefore) let the writer skip
 * a night that changed since; null maxStay(Before) means "no maximum". alsoPricing says the
 * pricing system pushes this field to the PMS for the room, so the change goes there too.
 */
final class StayChange
{
    public readonly DateTimeImmutable $night;

    /**
     * @throws InvalidArgumentException when the change sets nothing or a stay is below one night
     */
    public function __construct(
        public readonly string $roomCode,
        DateTimeImmutable $night,
        public readonly ChangeTarget $target,
        public readonly StayReason $reason,
        public readonly ?int $minStay = null,
        public readonly ?int $minStayBefore = null,
        public readonly ?int $maxStay = null,
        public readonly ?int $maxStayBefore = null,
        public readonly bool $alsoPricing = false,
        public readonly ?OrphanKind $orphan = null,
        public readonly ?OrphanProof $proof = null,
        public readonly ?string $holiday = null,
        public readonly ?string $season = null,
        public readonly bool $keepReason = false,
    ) {
        if ($minStay === null && $maxStay === null) {
            throw new InvalidArgumentException('A stay change sets a minimum or a maximum stay.');
        }
        foreach ([$minStay, $minStayBefore, $maxStay, $maxStayBefore] as $stay) {
            if ($stay !== null && $stay < 1) {
                throw new InvalidArgumentException('Stay lengths must be at least one night.');
            }
        }
        $this->night = Days::normalize($night);
    }

    /**
     * The fields that make two changes the same write (room, night, values and the guard read).
     */
    public function identity(): string
    {
        return implode('|', ['stay', $this->roomCode, Days::key($this->night), $this->minStay, $this->minStayBefore, $this->maxStay]);
    }
}
