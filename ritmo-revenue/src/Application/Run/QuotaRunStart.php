<?php

declare(strict_types=1);

namespace Ritmo\Application\Run;

use DateTimeImmutable;
use Ritmo\Application\ShadowRun\RunContext;
use Ritmo\Application\Write\Quota\RecalcGuard;
use Ritmo\Domain\Operations\SlotKind;
use Ritmo\Domain\Support\Clock;
use Ritmo\Infrastructure\Db\Repository\RunRepository;
use Ritmo\Infrastructure\Schedule\ScheduledRunRepository;

/**
 * The start of the "same run" of the recalculation quota (WRITE.md section 6, D6): a failed
 * recalculation is not tried again before the next scheduled routine. A run of a full or light
 * slot starts its routine at the slot's time; any other run (started by hand, an approved write,
 * a retry) belongs to the latest scheduled routine before it, and waits at least the configured
 * cooldown after a failure (RecalcQuota::runStartFor). Reference: cota_recalculo.py, inicio_rodada.
 */
final class QuotaRunStart
{
    /** Slots read back to find the latest scheduled routine. */
    private const SLOTS_READ = 50;

    public function __construct(
        private readonly ScheduledRunRepository $slots,
        private readonly RunRepository $runs,
        private readonly RecalcGuard $guard,
        private readonly Clock $clock,
    ) {
    }

    public function of(RunContext $context): DateTimeImmutable
    {
        $own = $this->slots->forRun($context->runId);
        if ($own !== null && self::isRoutine($own->slot->kind)) {
            return $this->guard->runStart($own->slot->at, true);
        }
        $now = $this->clock->now();
        $routine = null;
        foreach ($this->slots->latest($context->property->id, self::SLOTS_READ) as $record) {
            if (self::isRoutine($record->slot->kind) && $record->slot->at <= $now) {
                $routine = $record->slot->at;
                break;
            }
        }
        $started = $this->runs->find($context->runId)['started_at'] ?? $now;

        return $this->guard->runStart($routine ?? $started, false);
    }

    private static function isRoutine(SlotKind $kind): bool
    {
        return $kind === SlotKind::Full || $kind === SlotKind::Light;
    }
}
