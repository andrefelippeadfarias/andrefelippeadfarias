<?php

declare(strict_types=1);

namespace Ritmo\Application\ShadowRun;

use Ritmo\Domain\Model\InventoryNight;
use Ritmo\Domain\Report\OccupancyWindows;
use Ritmo\Domain\Report\OverbookedNight;
use Ritmo\Domain\Report\OverbookingDetector;
use Ritmo\Domain\Report\PmsPricingMismatch;
use Ritmo\Domain\Report\SoldMismatch;
use Ritmo\Domain\Report\WindowOccupancy;
use Ritmo\Domain\Support\Clock;
use Ritmo\Domain\Support\Days;
use Ritmo\Infrastructure\Db\Repository\AlertRepository;
use Ritmo\Infrastructure\Db\Repository\BookingRepository;
use Ritmo\Infrastructure\Db\Repository\InventorySnapshotRepository;

/**
 * Measures the property from what was collected: occupancy in each window against its goal,
 * overbooked nights, nights where the pricing system counts other sales than the PMS, and the
 * push state of each listing. Problems become alerts.
 */
final class MeasureStage implements Stage
{
    public const NAME = 'measure';

    /** A listing whose last push to the PMS is older than this is reported. */
    public const PUSH_STALE_HOURS = 30;

    public function __construct(
        private readonly InventorySnapshotRepository $snapshots,
        private readonly BookingRepository $bookings,
        private readonly AlertRepository $alerts,
        private readonly Clock $clock,
    ) {
    }

    public function name(): string
    {
        return self::NAME;
    }

    public function run(RunContext $context): StageResult
    {
        $inventory = $this->inventory($context);
        $report = (new OccupancyWindows($context->rules))->measure($context->property, $context->rooms, $inventory, $context->today);
        $overbooked = (new OverbookingDetector())->detect($inventory);
        $mismatches = $context->readsPricingCalendar() ? $this->mismatches($context) : [];
        $push = $this->pushState($context);

        $alerts = [];
        foreach ($overbooked as $night) {
            $alerts[] = ['overbooked', AlertRepository::CRITICAL, 'A night is sold beyond the units of the room.', ['units_over' => $night->unitsOver()], $night->roomCode, $night->night];
        }
        foreach ($mismatches as $mismatch) {
            $alerts[] = ['sold_mismatch', AlertRepository::WARNING, 'The pricing system counts other sales than the PMS bookings for a night.', ['pms_sold' => $mismatch->pmsSold, 'pricing_sold' => $mismatch->pricingSold], $mismatch->roomCode, $mismatch->night];
        }
        foreach ($push as $code => $listing) {
            if ($listing['problem'] !== null) {
                $message = $listing['problem'] === 'push_disabled'
                    ? 'The pricing system does not send prices of this listing to the PMS.'
                    : sprintf('The last price push of this listing is older than %d hours.', self::PUSH_STALE_HOURS);
                $alerts[] = [$listing['problem'], AlertRepository::WARNING, $message, ['last_pushed_at' => $listing['last_pushed_at']], $code, null];
            }
        }

        return new StageResult(
            [
                'occupancy' => [
                    'property' => self::windows($report->property),
                    'rooms' => array_map(self::windows(...), $report->rooms),
                    'under_goal' => count($report->windowsUnderGoal()),
                ],
                'overbooked' => array_map(static fn (OverbookedNight $n): array => ['room' => $n->roomCode, 'night' => Days::key($n->night), 'units_over' => $n->unitsOver()], $overbooked),
                'sold_mismatches' => array_map(static fn (SoldMismatch $m): array => ['room' => $m->roomCode, 'night' => Days::key($m->night), 'pms' => $m->pmsSold, 'pricing' => $m->pricingSold], $mismatches),
                'sold_mismatch_checked' => $context->readsPricingCalendar(),
                'push' => $push,
                'alerts_raised' => count($alerts),
            ],
            [],
            function () use ($alerts, $context): void {
                foreach ($alerts as [$code, $severity, $message, $facts, $room, $night]) {
                    $key = implode(':', [$code, $context->property->id, $room, $night === null ? '' : Days::key($night)]);
                    $this->alerts->raise($code, $severity, $message, $facts, $context->property->id, $room, $night, $key, $context->runId);
                }
            },
        );
    }

    /**
     * The PMS calendar captured by this run, by room code.
     *
     * @return array<string, list<InventoryNight>>
     */
    private function inventory(RunContext $context): array
    {
        $inventory = [];
        foreach ($context->rooms as $room) {
            $inventory[$room->code] = array_values($this->snapshots->latest($room->code, $context->horizon->first(), $context->horizon->last(), $context->capturedAt()));
        }

        return $inventory;
    }

    /**
     * @return list<SoldMismatch>
     */
    private function mismatches(RunContext $context): array
    {
        $pricing = array_map('array_values', $context->pricingNights());

        return (new PmsPricingMismatch())->find(
            $this->bookings->overlapping($context->horizon->first(), $context->horizon->last()),
            $pricing,
            $context->horizon
        );
    }

    /**
     * Push state of the listing of each room; "problem" is null, "push_disabled" or "push_stale".
     *
     * @return array<string, array{ref: string, push_enabled: bool, last_pushed_at: string|null, hours_since_push: int|null, problem: string|null}>
     */
    private function pushState(RunContext $context): array
    {
        $now = $this->clock->now();
        $state = [];
        foreach ($context->listings() as $code => $listing) {
            $pushedAt = StateCodec::instant(is_string($listing['last_pushed_at'] ?? null) ? $listing['last_pushed_at'] : null);
            $seconds = $pushedAt === null ? null : $now->getTimestamp() - $pushedAt->getTimestamp();
            $enabled = ($listing['push_enabled'] ?? false) === true;
            $state[$code] = [
                'ref' => (string) ($listing['ref'] ?? ''),
                'push_enabled' => $enabled,
                'last_pushed_at' => $pushedAt?->format(DATE_ATOM),
                'hours_since_push' => $seconds === null ? null : intdiv($seconds, 3600),
                'problem' => match (true) {
                    !$enabled => 'push_disabled',
                    $seconds === null || $seconds > self::PUSH_STALE_HOURS * 3600 => 'push_stale',
                    default => null,
                },
            ];
        }

        return $state;
    }

    /**
     * @param array<int, WindowOccupancy> $windows
     *
     * @return array<int, array{sold: int, capacity: int, percent: float|null, goal: float, meets_goal: bool|null}>
     */
    private static function windows(array $windows): array
    {
        return array_map(static fn (WindowOccupancy $w): array => [
            'sold' => $w->sold,
            'capacity' => $w->capacity,
            'percent' => $w->percent,
            'goal' => $w->goal,
            'meets_goal' => $w->meetsGoal(),
        ], $windows);
    }
}
