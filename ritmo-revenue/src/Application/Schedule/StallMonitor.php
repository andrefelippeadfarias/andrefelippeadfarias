<?php

declare(strict_types=1);

namespace Ritmo\Application\Schedule;

use DateTimeImmutable;
use DateTimeZone;
use Ritmo\Application\Alerts\AlertService;
use Ritmo\Contracts\KeyValueStore;
use Ritmo\Domain\Alerts\OperationAlerts;
use Ritmo\Domain\Support\Clock;
use Ritmo\Infrastructure\Schedule\ScheduledRunRepository;

/**
 * Notices a scheduler that does not run (operations spec 3.7): called from an admin page load or
 * the REST status, it raises scheduler_stalled when a slot has been due for longer than the limit
 * and the last tick is older than that too; the next tick resolves it.
 */
final class StallMonitor
{
    public function __construct(
        private readonly KeyValueStore $store,
        private readonly ScheduledRunRepository $slots,
        private readonly AlertService $alerts,
        private readonly Clock $clock,
    ) {
    }

    /**
     * @return bool true when the scheduler is stalled
     */
    public function check(int $stalledAfterMinutes): bool
    {
        $now = $this->clock->now();
        $limit = $now->modify(sprintf('-%d minutes', max(1, $stalledAfterMinutes)));
        $oldest = $this->slots->oldestDueAt(null, $now);
        $heartbeat = $this->heartbeat();
        if ($oldest === null || $oldest > $limit || ($heartbeat !== null && $heartbeat > $limit)) {
            return false;
        }
        $this->alerts->raise(OperationAlerts::schedulerStalled($heartbeat, $oldest));

        return true;
    }

    /**
     * The instant of the last tick, if any.
     */
    public function heartbeat(): ?DateTimeImmutable
    {
        $value = $this->store->getFresh(SchedulerTick::HEARTBEAT_KEY);
        if (!is_string($value)) {
            return null;
        }
        $at = DateTimeImmutable::createFromFormat('Y-m-d\TH:i:s\Z', $value, new DateTimeZone('UTC'));

        return $at === false ? null : $at;
    }
}
