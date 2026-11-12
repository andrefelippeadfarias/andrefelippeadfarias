<?php

declare(strict_types=1);

namespace Ritmo\Domain\Calendar\Stay;

use DateTimeInterface;
use Ritmo\Domain\Support\Days;

/**
 * A closed package: dates with their own stay and arrival/departure, for every room (empty rooms)
 * or the listed ones. The stay of a package night is the package's: no other rule lowers it and
 * no orphan stay applies there.
 *
 * Reference: parametros.regra_h_beds24 (estadia_minima_por_data, estadia_maxima_por_data,
 * override_por_data), comum.data_de_pacote and estadia_pacote.
 */
final class Package
{
    /**
     * @param list<string>                $rooms  room codes; empty for every room
     * @param array<string, PackageNight> $nights 'YYYY-MM-DD' => night
     */
    public function __construct(
        public readonly string $name,
        public readonly array $rooms,
        public readonly array $nights,
    ) {
    }

    public function night(string $roomCode, DateTimeInterface $night): ?PackageNight
    {
        if ($this->rooms !== [] && !in_array($roomCode, $this->rooms, true)) {
            return null;
        }

        return $this->nights[Days::key($night)] ?? null;
    }
}
