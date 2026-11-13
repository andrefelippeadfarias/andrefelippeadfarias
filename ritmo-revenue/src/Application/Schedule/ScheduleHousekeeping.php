<?php

declare(strict_types=1);

namespace Ritmo\Application\Schedule;

use Ritmo\Contracts\KeyValueStore;
use Ritmo\Domain\Support\Clock;
use Ritmo\Infrastructure\Schedule\BookingMovementRepository;
use Ritmo\Infrastructure\Schedule\ScheduledRunRepository;

/**
 * The daily purge of the scheduler's tables (operations spec 3.7 and 8.1), done by the first tick
 * of each UTC day: slot rows that ended more than SLOT_RETENTION_DAYS ago (a due or running slot
 * is never purged), and the processed booking versions of bookings that departed more than
 * MOVEMENT_RETENTION_DAYS ago, which is before "today - 7" in every timezone. Those versions only
 * dedupe movement of nights that can still change; a departed booking has none.
 */
final class ScheduleHousekeeping
{
    public const DAY_KEY = 'scheduler_housekeeping_day';

    public const SLOT_RETENTION_DAYS = 90;

    public const MOVEMENT_RETENTION_DAYS = 8;

    public function __construct(
        private readonly ScheduledRunRepository $slots,
        private readonly BookingMovementRepository $movements,
        private readonly KeyValueStore $store,
        private readonly Clock $clock,
    ) {
    }

    /**
     * Purges once per UTC day.
     *
     * @return bool true when it purged on this call
     */
    public function daily(): bool
    {
        $now = $this->clock->now();
        $day = $now->format('Y-m-d');
        if ($this->store->getFresh(self::DAY_KEY) === $day) {
            return false;
        }
        $this->slots->purgeBefore($now->modify(sprintf('-%d days', self::SLOT_RETENTION_DAYS)));
        $this->movements->purgeDepartedBefore($this->clock->today('UTC')->modify(sprintf('-%d days', self::MOVEMENT_RETENTION_DAYS)));
        $this->store->set(self::DAY_KEY, $day);

        return true;
    }
}
