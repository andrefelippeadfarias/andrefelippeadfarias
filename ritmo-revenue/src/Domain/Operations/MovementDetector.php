<?php

declare(strict_types=1);

namespace Ritmo\Domain\Operations;

use Ritmo\Domain\Model\BookingRecord;

/**
 * Turns the bookings of one read into the movement of a property.
 *
 * - Only bookings of the property's mapped rooms count; the others are only counted.
 * - A booking whose version (fingerprint) was already processed is not movement, whatever the
 *   overlap of the reads (L 08/11: a window five minutes earlier brought three bookings again).
 * - The first read of a property is a baseline: every booking is recorded and nothing moves.
 * - With a previous version the status transition decides the kind; without one, the reference's
 *   classification ({@see MovementClassifier::classify()}).
 */
final class MovementDetector
{
    /**
     * @param list<BookingRecord>           $read     the bookings of the read
     * @param array<string, BookingVersion> $previous PMS reference => version processed last
     * @param array<string, true>           $rooms    the property's room codes
     */
    public function detect(array $read, array $previous, MovementWindow $window, array $rooms): MovementBatch
    {
        $movements = [];
        $versions = [];
        $unmapped = 0;
        foreach ($read as $booking) {
            if ($booking->roomCode === null || !isset($rooms[$booking->roomCode])) {
                ++$unmapped;
                continue;
            }
            $version = BookingVersion::of($booking);
            $before = $previous[$booking->pmsRef] ?? null;
            if ($before !== null && $before->fingerprint() === $version->fingerprint()) {
                continue;
            }
            $kind = $window->baseline ? MovementKind::Baseline : self::kind($booking, $before, $window);
            $versions[] = [$version, $kind];
            if ($kind !== MovementKind::Baseline) {
                $movements[] = new Movement($kind, $booking, $before);
            }
        }

        return new MovementBatch($movements, $versions, $unmapped);
    }

    private static function kind(BookingRecord $booking, ?BookingVersion $before, MovementWindow $window): MovementKind
    {
        return $before === null ? MovementClassifier::classify($booking, $window->since) : MovementClassifier::against($booking, $before);
    }
}
