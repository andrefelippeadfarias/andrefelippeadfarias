<?php

declare(strict_types=1);

namespace Ritmo\Application\Grid;

use DateTimeImmutable;
use Ritmo\Domain\Model\Property;
use Ritmo\Domain\Model\RoomType;

/**
 * The Nights grid of one property: a page of rooms by a window of nights. A cell filtered out
 * is null; every room and night of the window is present.
 */
final class NightGrid
{
    /**
     * @param list<RoomType>                                 $rooms    the rooms of this page, in their order
     * @param list<DateTimeImmutable>                        $nights   the window, first to last
     * @param array<string, array<string, NightCell|null>>   $cells    room code => "YYYY-MM-DD" => cell
     * @param array<string, string>                          $holidays "YYYY-MM-DD" => period name
     * @param array<string, mixed>|null                      $run      the run whose plan the cells show
     */
    public function __construct(
        public readonly Property $property,
        public readonly array $rooms,
        public readonly int $roomsInAll,
        public readonly int $roomOffset,
        public readonly array $nights,
        public readonly array $cells,
        public readonly array $holidays,
        public readonly ?array $run,
        public readonly NightFilter $filter,
    ) {
    }

    public function cell(string $room, string $night): ?NightCell
    {
        return $this->cells[$room][$night] ?? null;
    }

    /** Cells left by the filter (the "grid-count" of the screen). */
    public function shown(): int
    {
        $count = 0;
        foreach ($this->cells as $nights) {
            $count += count(array_filter($nights, static fn (?NightCell $c): bool => $c !== null));
        }

        return $count;
    }

    public function first(): ?DateTimeImmutable
    {
        return $this->nights[0] ?? null;
    }

    public function last(): ?DateTimeImmutable
    {
        return $this->nights === [] ? null : $this->nights[count($this->nights) - 1];
    }
}
