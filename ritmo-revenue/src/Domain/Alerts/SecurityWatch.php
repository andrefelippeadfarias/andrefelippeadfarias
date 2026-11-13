<?php

declare(strict_types=1);

namespace Ritmo\Domain\Alerts;

use DateTimeImmutable;
use Ritmo\Domain\Model\BookingRecord;
use Ritmo\Domain\Write\Hold\CancellationWave;

/**
 * The security watch over the bookings of a run (operations spec 5.2).
 *
 * A booking is inspected when it was changed (or, without a change instant, created) at or after
 * $since; bookings of rooms the property did not map are outside the product (deliberate
 * difference OD9). Findings, per booking in order: an origin outside the known list (non-empty,
 * compared without case, only once the list is confirmed); a confirmed or new booking without
 * value (total missing or 0 or less), "modified" when it was created before $since. Then one
 * finding per burst of cancellations ({@see CancellationWave}: every wave, not only the first).
 *
 * Reference: vigia_seguranca.classificar. The reference reads the raw price; this reads
 * BookingRecord::total (invoice charges, or the price): deliberate difference OD5.
 */
final class SecurityWatch
{
    public function __construct(
        private readonly SecurityConfig $config,
        private readonly CancellationWave $waves,
    ) {
    }

    /**
     * @param list<BookingRecord> $bookings
     *
     * @return list<SecurityFinding>
     */
    public function inspect(array $bookings, DateTimeImmutable $since): array
    {
        if (!$this->config->enabled) {
            return [];
        }
        $mapped = array_values(array_filter($bookings, static fn (BookingRecord $b): bool => $b->roomCode !== null));
        $findings = [];
        foreach ($this->inWindow($mapped, $since) as $booking) {
            $origin = trim($booking->origin);
            if ($this->config->originsConfirmed && $origin !== '' && !$this->config->isKnown($origin)) {
                $findings[] = SecurityFinding::booking(SecurityFindingKind::UnknownOrigin, $booking);
            }
            if ($this->config->zeroValue && $booking->status->occupies() && ($booking->total === null || $booking->total <= 0)) {
                $created = $booking->createdAt !== null && $booking->createdAt >= $since;
                $findings[] = SecurityFinding::booking($created ? SecurityFindingKind::ZeroValue : SecurityFindingKind::ZeroValueModified, $booking);
            }
        }
        foreach ($this->waves->detect($mapped, $since) as $wave) {
            $findings[] = SecurityFinding::burst($wave);
        }

        return $findings;
    }

    /**
     * The origins of the bookings changed since $since, for the report while the known list is
     * not confirmed (learning).
     *
     * @param list<BookingRecord> $bookings
     *
     * @return list<string> distinct, sorted
     */
    public function originsSeen(array $bookings, DateTimeImmutable $since): array
    {
        $seen = [];
        foreach ($this->inWindow($bookings, $since) as $booking) {
            $origin = trim($booking->origin);
            if ($origin !== '') {
                $seen[$origin] = true;
            }
        }
        $origins = array_map('strval', array_keys($seen));
        sort($origins);

        return $origins;
    }

    /**
     * @param list<BookingRecord> $bookings
     *
     * @return list<BookingRecord>
     */
    private function inWindow(array $bookings, DateTimeImmutable $since): array
    {
        return array_values(array_filter($bookings, static function (BookingRecord $b) use ($since): bool {
            $at = $b->modifiedAt ?? $b->createdAt;

            return $at !== null && $at >= $since;
        }));
    }
}
