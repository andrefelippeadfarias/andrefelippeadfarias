<?php

declare(strict_types=1);

namespace Ritmo\Domain\Operations;

use DateTimeImmutable;
use Ritmo\Domain\Model\BookingRecord;
use Ritmo\Domain\Model\BookingStatus;

/**
 * Classifies a booking read since an instant.
 *
 * Reference: beds24.movimento_de and beds24.alterada_desde. The reference compares ISO strings;
 * this compares instants (deliberate difference OD3 of the operations spec: strings of different
 * precision sort wrongly).
 */
final class MovementClassifier
{
    /**
     * Without a stored previous version: cancelled when cancelled at or after $since, else new when
     * created at or after $since, else modified. A cancellation without its instant is "modified",
     * as in the reference.
     */
    public static function classify(BookingRecord $booking, DateTimeImmutable $since): MovementKind
    {
        if ($booking->status === BookingStatus::Cancelled && $booking->cancelledAt !== null && $booking->cancelledAt >= $since) {
            return MovementKind::Cancelled;
        }
        if ($booking->createdAt !== null && $booking->createdAt >= $since) {
            return MovementKind::New;
        }

        return MovementKind::Modified;
    }

    /**
     * With the version processed before: the status transition decides (a booking that became
     * cancelled is a cancellation even when the PMS gave no cancellation instant; deliberate
     * difference OD4); anything else is a modification.
     */
    public static function against(BookingRecord $booking, BookingVersion $previous): MovementKind
    {
        return $booking->status === BookingStatus::Cancelled && $previous->status !== BookingStatus::Cancelled
            ? MovementKind::Cancelled
            : MovementKind::Modified;
    }

    /**
     * Created or changed at or after $since (the cut of the PMS read). A booking without any
     * timestamp counts.
     */
    public static function changedSince(BookingRecord $booking, DateTimeImmutable $since): bool
    {
        $at = $booking->modifiedAt ?? $booking->createdAt;

        return $at === null || $at >= $since;
    }
}
