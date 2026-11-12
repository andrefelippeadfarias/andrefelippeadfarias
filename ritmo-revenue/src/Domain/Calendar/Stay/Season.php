<?php

declare(strict_types=1);

namespace Ritmo\Domain\Calendar\Stay;

use DateTimeInterface;
use Ritmo\Domain\Support\DateRange;
use Ritmo\Domain\Support\Days;

/**
 * A period of the season calendar, on some weekdays, with its own minimum stay or none.
 *
 * The first period that covers a night is the night's season. A season above the arrival rule
 * raises it; inside a holiday period the holiday's own stay comes first and the season fills in
 * when the holiday has none.
 *
 * Reference: sazonalidade.periodo, validar_plano.estadia_temporada and periodo_temporada.
 */
final class Season
{
    /**
     * @param list<int> $weekdays Monday = 0 ... Sunday = 6
     */
    public function __construct(
        public readonly string $name,
        public readonly DateRange $nights,
        public readonly array $weekdays,
        public readonly ?int $minStay,
    ) {
    }

    public function covers(DateTimeInterface $night): bool
    {
        return $this->nights->contains($night) && in_array(Days::weekday($night), $this->weekdays, true);
    }
}
