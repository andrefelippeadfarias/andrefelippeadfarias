<?php

declare(strict_types=1);

namespace Ritmo\Domain\Write\Hold;

use DateTimeImmutable;
use Ritmo\Domain\Model\BookingRecord;
use Ritmo\Domain\Model\BookingStatus;
use Ritmo\Domain\Support\Days;

/**
 * Detects every wave of cancellations since an instant, not only the first: per channel, each
 * cancellation inside a window holding N or more within H hours is marked, and the marked ones
 * are grouped while each is at most H hours after the previous. Bookings modified before the
 * instant, and cancellations before it, do not count.
 *
 * Reference: vigia_seguranca.classificar ("rajada de cancelamentos"; AP 06/11: every wave of the
 * run, not only the first).
 */
final class CancellationWave
{
    public function __construct(private readonly WaveConfig $config = new WaveConfig())
    {
    }

    /**
     * @param list<BookingRecord> $bookings
     *
     * @return list<Wave> by channel, then by time
     */
    public function detect(array $bookings, DateTimeImmutable $since): array
    {
        $byChannel = [];
        foreach ($bookings as $booking) {
            $touched = $booking->modifiedAt ?? $booking->createdAt;
            if ($touched === null || $touched < $since || $booking->status !== BookingStatus::Cancelled) {
                continue;
            }
            $at = $booking->cancelledAt;
            if ($at !== null && $at >= $since) {
                $byChannel[$booking->channel === '' ? '?' : $booking->channel][] = [$at, $booking];
            }
        }
        $waves = [];
        foreach ($byChannel as $channel => $cancelled) {
            usort($cancelled, static fn (array $a, array $b): int => $a[0] <=> $b[0]);
            foreach ($this->groups($cancelled) as $group) {
                $waves[] = self::wave((string) $channel, $group);
            }
        }

        return $waves;
    }

    /**
     * @param list<array{0: DateTimeImmutable, 1: BookingRecord}> $cancelled in cancellation order
     *
     * @return list<list<array{0: DateTimeImmutable, 1: BookingRecord}>>
     */
    private function groups(array $cancelled): array
    {
        $window = $this->config->windowHours * 3600;
        $marked = [];
        $start = 0;
        foreach ($cancelled as $j => $booking) {
            while ($booking[0]->getTimestamp() - $cancelled[$start][0]->getTimestamp() > $window) {
                ++$start;
            }
            if ($j - $start + 1 >= $this->config->minCancellations) {
                for ($k = $start; $k <= $j; ++$k) {
                    $marked[$k] = true;
                }
            }
        }
        ksort($marked);
        $groups = [];
        $current = [];
        foreach (array_keys($marked) as $k) {
            if ($current !== [] && $cancelled[$k][0]->getTimestamp() - $current[count($current) - 1][0]->getTimestamp() > $window) {
                $groups[] = $current;
                $current = [];
            }
            $current[] = $cancelled[$k];
        }
        if ($current !== []) {
            $groups[] = $current;
        }

        return $groups;
    }

    /**
     * @param list<array{0: DateTimeImmutable, 1: BookingRecord}> $group
     */
    private static function wave(string $channel, array $group): Wave
    {
        $nights = [];
        foreach ($group as [, $booking]) {
            if ($booking->roomCode !== null) {
                foreach ($booking->nights() as $night) {
                    $nights[$booking->roomCode][Days::key($night)] = true;
                }
            }
        }
        ksort($nights);

        return new Wave(
            $channel,
            array_map(static fn (array $c): string => $c[1]->pmsRef, $group),
            $group[0][0],
            $group[count($group) - 1][0],
            array_map(static fn (array $keys): array => array_keys($keys), $nights),
        );
    }
}
