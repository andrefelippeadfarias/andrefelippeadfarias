<?php

declare(strict_types=1);

namespace Ritmo\Domain\Relations\Coherence;

use DateTimeInterface;
use Ritmo\Domain\Support\Days;

/**
 * The key of a room-night: "ROOM|YYYY-MM-DD".
 */
final class RoomNight
{
    public static function key(string $room, DateTimeInterface $night): string
    {
        return $room . '|' . Days::key($night);
    }

    /**
     * @return array{0: string, 1: string} room code and night as YYYY-MM-DD
     */
    public static function split(string $key): array
    {
        $parts = explode('|', $key, 2);

        return [$parts[0], $parts[1] ?? ''];
    }
}
