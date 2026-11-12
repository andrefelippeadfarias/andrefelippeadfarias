<?php

declare(strict_types=1);

namespace Ritmo\Domain\Calendar\Stay;

use DateTimeImmutable;
use DateTimeInterface;
use Ritmo\Domain\Support\Days;

/**
 * Minimum stay by the weekday of arrival, for the nights from validFrom to validTo.
 *
 * The site table (byWeekday) can be overridden per property and per room, weekday by weekday;
 * the room wins over the property. Held weekdays of a room stay as they are (nothing raises or
 * lowers them) until someone decides.
 *
 * Reference: comum.regra_dia_chegada, estadia_do_dia, aguardando_dono.
 */
final class ArrivalRule
{
    /**
     * @param array<int, int>                  $byWeekday         weekday (Monday = 0) => nights, all seven
     * @param array<string, array<int, int>>   $propertyWeekdays  property id => weekday => nights
     * @param array<string, array<int, int>>   $roomWeekdays      room code => weekday => nights
     * @param array<string, list<int>>         $heldWeekdays      room code => weekdays held
     */
    public function __construct(
        public readonly bool $enabled = false,
        public readonly ?DateTimeImmutable $validFrom = null,
        public readonly ?DateTimeImmutable $validTo = null,
        public readonly array $byWeekday = [1, 1, 1, 1, 1, 1, 1],
        public readonly array $propertyWeekdays = [],
        public readonly array $roomWeekdays = [],
        public readonly array $heldWeekdays = [],
    ) {
    }

    public function inForce(DateTimeInterface $night): bool
    {
        $key = Days::key($night);

        return $this->enabled
            && ($this->validFrom === null || $key >= Days::key($this->validFrom))
            && ($this->validTo === null || $key <= Days::key($this->validTo));
    }

    /**
     * The stay of the night's weekday for the room, or null while the rule is not in force.
     */
    public function stayFor(string $roomCode, string $propertyId, DateTimeInterface $night): ?int
    {
        if (!$this->inForce($night)) {
            return null;
        }
        $weekday = Days::weekday($night);

        return $this->roomWeekdays[$roomCode][$weekday]
            ?? $this->propertyWeekdays[$propertyId][$weekday]
            ?? $this->byWeekday[$weekday];
    }

    public function isHeld(string $roomCode, DateTimeInterface $night): bool
    {
        return $this->inForce($night) && in_array(Days::weekday($night), $this->heldWeekdays[$roomCode] ?? [], true);
    }
}
