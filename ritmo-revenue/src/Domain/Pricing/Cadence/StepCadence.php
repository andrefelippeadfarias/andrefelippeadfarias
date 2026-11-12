<?php

declare(strict_types=1);

namespace Ritmo\Domain\Pricing\Cadence;

use DateTimeImmutable;
use Ritmo\Domain\Orders\OwnerOrder;
use Ritmo\Domain\Pricing\StepDirection;
use Ritmo\Domain\Support\Days;

/**
 * One step per night per day and direction.
 *
 * Only the last step of the room night counts. A step in the other direction goes at once; a
 * booking (going up) or a cancellation (going down) since that step releases one more, when it
 * was counted over the same base and the same total; otherwise the previous step must have
 * reached the sales channel and be at least the minimum interval old (exactly the interval is
 * enough).
 *
 * Reference: pace.cadencia_permite and pace.ordem_nova_sem_passo.
 */
final class StepCadence
{
    public function __construct(private readonly CadenceConfig $config = new CadenceConfig())
    {
    }

    /**
     * @param int|null $sold  units sold now, counted over $base; null leaves the booking exception out
     * @param int|null $total units the count is over; null matches any total
     */
    public function permits(
        StepLedger $ledger,
        string $room,
        DateTimeImmutable $night,
        StepDirection $direction,
        DateTimeImmutable $now,
        ?int $sold = null,
        SoldBase $base = SoldBase::Room,
        ?int $total = null,
    ): CadenceVerdict {
        $last = $ledger->lastStep($room, $night);
        if ($last === null || ($last->direction !== $direction && $this->config->oppositeDirectionImmediate)) {
            return CadenceVerdict::allow($last);
        }
        if ($last->direction === $direction && $this->bookingReleases($last, $direction, $sold, $base, $total)) {
            return CadenceVerdict::allow($last);
        }
        if ($this->config->requireChannelArrival && $last->onChannelAt === null) {
            return CadenceVerdict::hold(CadenceVerdict::NOT_ON_CHANNEL, $last);
        }
        $elapsed = (float) $now->format('U.u') - (float) $last->writtenAt->format('U.u');

        return $elapsed < $this->config->minHoursBetweenSteps * 3600
            ? CadenceVerdict::hold(CadenceVerdict::WITHIN_INTERVAL, $last)
            : CadenceVerdict::allow($last);
    }

    /**
     * An owner order given today takes effect at once, once: it must carry its creation instant
     * and no step of the room night may have been written at or after it.
     */
    public function newOrderBypass(StepLedger $ledger, string $room, DateTimeImmutable $night, ?OwnerOrder $order, DateTimeImmutable $today): bool
    {
        if (!$this->config->newOrderImmediateOnce || $order === null || $order->createdAt === null || $order->givenOn === null) {
            return false;
        }

        return $order->givenOn == Days::normalize($today) && !$ledger->hasStepSince($room, $night, $order->createdAt);
    }

    private function bookingReleases(StepRecord $last, StepDirection $direction, ?int $sold, SoldBase $base, ?int $total): bool
    {
        if (!$this->config->releaseOnBooking || $sold === null || $last->sold === null || $last->soldBase !== $base) {
            return false;
        }
        if ($total !== null && $last->soldTotal !== null && $last->soldTotal !== $total) {
            return false;
        }

        return $direction === StepDirection::Up ? $sold > $last->sold : $sold < $last->sold;
    }
}
