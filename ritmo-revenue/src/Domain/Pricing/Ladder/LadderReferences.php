<?php

declare(strict_types=1);

namespace Ritmo\Domain\Pricing\Ladder;

use Ritmo\Domain\Model\PriceNight;

/**
 * The prices the relations of a ladder night read for each room: what really stays on the channel
 * when it is known (an item's effective value, or the live price of a room that got no item),
 * else the ladder price, else the live calendar price (a room outside the ladder: frozen, fixed
 * by order, blocked). A room sells when it has a calendar night, is not sold out and is not blocked.
 *
 * Reference: pace.referencias_recanto.
 */
final class LadderReferences
{
    /**
     * @param array<string, PriceNight> $rows    calendar nights of the night, by room code
     * @param array<string, true>       $blocked rooms blocked without a booking that night
     * @param array<string, float>|null $reals   what stays on the channel, by room code, once known
     */
    public function __construct(
        private readonly array $rows,
        private readonly array $blocked = [],
        private readonly ?array $reals = null,
    ) {
    }

    public function valueOf(LadderNight $night, string $room): ?float
    {
        if ($this->reals !== null && array_key_exists($room, $this->reals)) {
            return $this->reals[$room];
        }
        $quote = $night->quote($room);
        if ($quote !== null) {
            return $quote->sent;
        }

        return isset($this->rows[$room]) ? $this->rows[$room]->price : null;
    }

    public function sells(string $room): bool
    {
        $row = $this->rows[$room] ?? null;

        return $row !== null && !$row->isSoldOut() && !isset($this->blocked[$room]);
    }
}
