<?php

declare(strict_types=1);

namespace Ritmo\Domain\Calendar\Stay;

use DateTimeInterface;
use Ritmo\Domain\Model\Restriction;
use Ritmo\Domain\Support\Days;

/**
 * A weekday closed for arrival and/or departure in the PMS, for every room (empty rooms) or the
 * listed ones, except the excepted rooms.
 *
 * minStay, when set, is the closed day's own stay: the arrival rule does not decide the night and
 * a stay below it is reported. openDates give single dates their own restriction (for example a
 * festive date open for arrival and departure).
 *
 * Reference: parametros.regra_h_beds24 (sabado_override, estadia_minima_sabado, sabados_excecao,
 * orfas, sexta_override_por_quarto).
 */
final class ClosedDayRule
{
    /**
     * @param list<string>               $rooms       room codes; empty for every room
     * @param list<string>               $exceptRooms room codes the rule skips
     * @param array<string, Restriction> $openDates   'YYYY-MM-DD' => restriction of that date
     */
    public function __construct(
        public readonly int $weekday,
        public readonly Restriction $restriction,
        public readonly array $rooms = [],
        public readonly array $exceptRooms = [],
        public readonly ?int $minStay = null,
        public readonly array $openDates = [],
        public readonly OrphanSide $orphanSide = new OrphanSide(),
    ) {
    }

    public function appliesTo(string $roomCode, DateTimeInterface $night): bool
    {
        return Days::weekday($night) === $this->weekday
            && ($this->rooms === [] || in_array($roomCode, $this->rooms, true))
            && !in_array($roomCode, $this->exceptRooms, true);
    }

    public function openDate(DateTimeInterface $night): ?Restriction
    {
        return $this->openDates[Days::key($night)] ?? null;
    }
}
