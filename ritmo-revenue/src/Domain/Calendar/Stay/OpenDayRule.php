<?php

declare(strict_types=1);

namespace Ritmo\Domain\Calendar\Stay;

use DateTimeInterface;
use Ritmo\Domain\Support\Days;

/**
 * A weekday enforced open for arrival and departure in the listed rooms: the audit clears an
 * arrival/departure restriction found there (a blackout stays) and the pricing plan opens that
 * weekday in the date override's masks.
 *
 * Reference: parametros.regra_h_beds24.sexta_reaberta_por_quarto, comum.sexta_reaberta.
 */
final class OpenDayRule
{
    /**
     * @param list<string> $rooms room codes
     */
    public function __construct(
        public readonly int $weekday,
        public readonly array $rooms,
    ) {
    }

    public function appliesTo(string $roomCode, DateTimeInterface $night): bool
    {
        return Days::weekday($night) === $this->weekday && in_array($roomCode, $this->rooms, true);
    }
}
