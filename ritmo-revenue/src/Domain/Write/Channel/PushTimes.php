<?php

declare(strict_types=1);

namespace Ritmo\Domain\Write\Channel;

use DateTimeImmutable;
use DateTimeZone;
use Exception;
use Ritmo\Domain\Model\ListingInfo;

/**
 * The last push of each room to the channels, from the listing directory of the pricing system.
 * A listing with the push switched off, without a readable push instant or not mapped to a room is
 * left out and said why; when one room is listed twice the oldest push counts.
 *
 * Reference: pace.envios_da_coleta.
 */
final class PushTimes
{
    private const INSTANT = '/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}(:\d{2}(\.\d+)?)?(Z|[+-]\d{2}:?\d{2})?$/';

    /**
     * @param array<string, DateTimeImmutable> $pushedAt room => last push (UTC)
     * @param list<string>                      $leftOut  why a listing was left out, one line each
     */
    private function __construct(
        public readonly array $pushedAt,
        public readonly array $leftOut,
    ) {
    }

    /**
     * @param array<string, string> $rooms    listing reference => room code
     * @param list<ListingInfo>     $listings the listing directory as read
     */
    public static function fromListings(array $rooms, array $listings): self
    {
        $pushed = [];
        $out = [];
        foreach ($listings as $listing) {
            $room = $rooms[$listing->ref] ?? null;
            $at = $listing->sync->lastPushedAt?->setTimezone(new DateTimeZone('UTC'));
            if ($room === null) {
                $out[] = sprintf('%s: not mapped to a room', $listing->ref);
            } elseif (!$listing->sync->pushEnabled) {
                $out[] = sprintf('%s: push switched off in the pricing system', $room);
            } elseif ($at === null) {
                $out[] = sprintf('%s: no readable push instant', $room);
            } else {
                $pushed[$room] = isset($pushed[$room]) ? min($pushed[$room], $at) : $at;
            }
        }
        ksort($pushed);

        return new self($pushed, $out);
    }

    /**
     * A push instant as the directory gives it: ISO 8601, an instant without a zone is UTC;
     * anything else (empty, "N/A", a date only) is null.
     */
    public static function parseInstant(mixed $value): ?DateTimeImmutable
    {
        if (!is_string($value) || preg_match(self::INSTANT, trim($value)) !== 1) {
            return null;
        }
        try {
            return (new DateTimeImmutable(trim($value), new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('UTC'));
        } catch (Exception) {
            return null;
        }
    }
}
