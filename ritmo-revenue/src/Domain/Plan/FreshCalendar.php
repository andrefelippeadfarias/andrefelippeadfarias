<?php

declare(strict_types=1);

namespace Ritmo\Domain\Plan;

use DateTimeImmutable;
use DateTimeInterface;
use Ritmo\Domain\Support\Num;

/**
 * The pricing calendar read just before validating: per room night, the price, the floor in force
 * and the units sold; per room, the instant the pricing system last recalculated it.
 *
 * Reference: validar_plano.precos_da_coleta (the calendar of --precos and its age in hours).
 */
final class FreshCalendar
{
    /**
     * @param array<string, CalendarNight>          $nights      PlanItem::keyOf() => night
     * @param array<string, DateTimeImmutable|null> $refreshedAt room code => instant of the last recalculation
     */
    public function __construct(
        private readonly array $nights = [],
        private readonly array $refreshedAt = [],
    ) {
    }

    public function night(string $roomCode, DateTimeInterface $night): ?CalendarNight
    {
        return $this->nights[PlanItem::keyOf($roomCode, $night)] ?? null;
    }

    /**
     * Hours since the room's calendar was recalculated, to one decimal; null when unknown.
     */
    public function ageHours(string $roomCode, DateTimeImmutable $now): ?float
    {
        $at = $this->refreshedAt[$roomCode] ?? null;
        if ($at === null) {
            return null;
        }

        return Num::roundHalfEven(((float) $now->format('U.u') - (float) $at->format('U.u')) / 3600, 1);
    }

    /**
     * @return array<string, CalendarNight>
     */
    public function nights(): array
    {
        return $this->nights;
    }
}
