<?php

declare(strict_types=1);

namespace Ritmo\Application\Dashboard\Overview;

use DateTimeImmutable;
use DateTimeZone;
use OutOfBoundsException;
use Ritmo\Application\Dashboard\Alerts\AlertFilter;
use Ritmo\Application\Dashboard\Alerts\AlertList;
use Ritmo\Application\Dashboard\OccupancyQuery;
use Ritmo\Application\Dashboard\StatusQuery;
use Ritmo\Application\Dashboard\Todo\TodoItem;
use Ritmo\Application\Dashboard\Todo\TodoStore;
use Ritmo\Application\Grid\LastPlan;
use Ritmo\Application\Grid\PlannedNight;
use Ritmo\Domain\Config\RuleSet;
use Ritmo\Domain\Model\Property;
use Ritmo\Domain\Model\RoomType;
use Ritmo\Domain\Support\Clock;
use Ritmo\Domain\Support\DateRange;
use Ritmo\Domain\Support\Days;
use Ritmo\Infrastructure\Db\Repository\BookingRepository;
use Ritmo\Infrastructure\Db\Repository\InventorySnapshotRepository;
use Ritmo\Infrastructure\Db\Repository\PropertyRepository;
use Ritmo\Infrastructure\Db\Repository\RoomTypeRepository;
use Ritmo\Infrastructure\Db\Repository\RuleSetRepository;

/**
 * The Overview (admin spec 5.2) from stored data only: tonight and the next three nights, the
 * 7- and 30-day windows with revenue on the books, the pace of the next 60 days, what needs a
 * person, the last run and the next runs. Each card is read on its own, so one failing card does
 * not take the others down (the screen catches per card).
 */
final class OverviewQuery
{
    public const SOON_NIGHTS = 4;

    public const PACE_DAYS = 60;

    private const NEEDS_YOU = 3;

    public function __construct(
        private readonly PropertyRepository $properties,
        private readonly RoomTypeRepository $roomTypes,
        private readonly RuleSetRepository $ruleSets,
        private readonly InventorySnapshotRepository $snapshots,
        private readonly BookingRepository $bookings,
        private readonly OccupancyQuery $occupancy,
        private readonly LastPlan $lastPlan,
        private readonly StatusQuery $status,
        private readonly AlertList $alerts,
        private readonly TodoStore $todos,
        private readonly Clock $clock,
    ) {
    }

    /**
     * One row per property (the "All properties" card).
     *
     * @return list<array{id: string, name: string, mode: string, free_tonight: int, units: int, signal: string, percent: float|null, goal: float|null, critical: int, warning: int, waiting: int, last_run: array<string, mixed>|null}>
     */
    public function forSite(): array
    {
        $rows = [];
        foreach ($this->properties->all() as $property) {
            $soon = $this->soon($property);
            $occupancy = $this->occupancy->forProperty($property->id);
            $window = $occupancy['property'][OccupancyQuery::SIGNAL_WINDOW] ?? null;
            $status = $this->status->forProperty($property->id);
            $rows[] = [
                'id' => $property->id,
                'name' => $property->name,
                'mode' => $property->mode->value,
                'free_tonight' => array_sum(array_map(static fn (array $r): int => $r['nights'][0]['free'] ?? 0, $soon['rooms'])),
                'units' => array_sum(array_map(static fn (array $r): int => $r['units'], $soon['rooms'])),
                'signal' => $occupancy['signal'],
                'percent' => $window['percent'] ?? null,
                'goal' => $window['goal'] ?? null,
                'critical' => $this->alerts->countBySeverity($property->id, ['critical']),
                'warning' => $this->alerts->countBySeverity($property->id, ['warning']),
                'waiting' => $status->waitingEntries,
                'last_run' => $status->lastRun,
            ];
        }

        return $rows;
    }

    /**
     * Tonight and the next nights: per room, free units, closed or sold out, and the price now.
     *
     * @return array{captured_at: DateTimeImmutable|null, nights: list<DateTimeImmutable>, rooms: list<array{code: string, name: string, units: int, nights: list<array{free: int, sold: int, closed: bool, price: float|null}>}>}
     */
    public function soon(Property $property): array
    {
        $range = DateRange::nights($this->clock->today($property->timezone), self::SOON_NIGHTS);
        $plan = $this->lastPlan->of($this->lastPlan->recentRuns($property->id, 5));
        $sold = $this->soldUnits($range);
        $rooms = [];
        foreach ($this->roomTypes->forProperty($property->id) as $room) {
            $calendar = $this->snapshots->latest($room->code, $range->first(), $range->last());
            $nights = [];
            foreach ($range as $night) {
                $key = Days::key($night);
                $units = $sold[$room->code][$key] ?? 0;
                $available = $calendar[$key]->available ?? null;
                $nights[] = [
                    'free' => $available === null ? max(0, $room->units - $units) : max(0, $available),
                    'sold' => $units,
                    'closed' => $available !== null && $available <= 0 && $units < $room->units,
                    'price' => $plan['plan']->price($room->code, $key) ?? ($calendar[$key]->pmsPrice ?? null),
                ];
            }
            $rooms[] = ['code' => $room->code, 'name' => $room->name, 'units' => $room->units, 'nights' => $nights];
        }

        return ['captured_at' => $plan['run']['started_at'] ?? null, 'nights' => iterator_to_array($range, false), 'rooms' => $rooms];
    }

    /**
     * The two occupancy windows of the card (7 and 30 days when the rules have them, else the
     * shortest and the longest goal window), with revenue on the books and the average daily rate.
     *
     * @return list<array{days: int, sold: int, capacity: int, percent: float|null, goal: float, signal: string, revenue: float, bookings: int, room_nights: int, rooms: list<array{code: string, name: string, sold: int, capacity: int, percent: float|null}>}>
     */
    public function windows(Property $property): array
    {
        $occupancy = $this->occupancy->forProperty($property->id);
        $windows = array_keys($occupancy['property']);
        if ($windows === []) {
            return [];
        }
        $chosen = array_values(array_unique([in_array(7, $windows, true) ? 7 : min($windows), in_array(30, $windows, true) ? 30 : max($windows)]));
        $rooms = $this->roomTypes->forProperty($property->id);
        $today = $this->clock->today($property->timezone);
        $bookings = $this->bookings->overlapping($today, Days::add($today, max($chosen) - 1));
        $out = [];
        foreach ($chosen as $days) {
            $out[] = $occupancy['property'][$days] + ['days' => $days] + Revenue::onTheBooks($bookings, $rooms, DateRange::nights($today, $days)) + [
                'rooms' => array_map(static fn (RoomType $r): array => [
                    'code' => $r->code,
                    'name' => $r->name,
                    'sold' => $occupancy['rooms'][$r->code][$days]['sold'] ?? 0,
                    'capacity' => $occupancy['rooms'][$r->code][$days]['capacity'] ?? 0,
                    'percent' => $occupancy['rooms'][$r->code][$days]['percent'] ?? null,
                ], $rooms),
            ];
        }

        return $out;
    }

    /**
     * Room nights of the last run's plan in the next 60 days by pace category.
     *
     * @return array{run_id: int|null, counts: array<string, int>, total: int}
     */
    public function pace(Property $property): array
    {
        $plan = $this->lastPlan->of($this->lastPlan->recentRuns($property->id, 10));
        $today = $this->clock->today($property->timezone);
        $last = Days::add($today, self::PACE_DAYS - 1);
        $counts = array_fill_keys(['ahead', 'on_pace', 'behind', 'very_behind', 'floor', 'none'], 0);
        foreach ($plan['plan']->all() as $key => $night) {
            $date = Days::date(substr($key, strrpos($key, '|') + 1));
            if ($date >= $today && $date <= $last) {
                $counts[$night->paceCategory()] = ($counts[$night->paceCategory()] ?? 0) + 1;
            }
        }

        return ['run_id' => $plan['plan']->runId, 'counts' => $counts, 'total' => array_sum($counts)];
    }

    /**
     * What waits for a person: plans, open critical alerts and to-do items due.
     *
     * @return array{waiting_requests: int, waiting_entries: int, expires: DateTimeImmutable|null, alerts: list<array<string, mixed>>, todos: list<TodoItem>}
     */
    public function needsYou(Property $property): array
    {
        $status = $this->status->forProperty($property->id);
        $today = $this->clock->today($property->timezone);

        return [
            'waiting_requests' => $status->waitingRequests,
            'waiting_entries' => $status->waitingEntries,
            'expires' => $status->waitingExpires,
            'alerts' => $this->alerts->page(new AlertFilter($property->id, 'open', 'critical'), 0, self::NEEDS_YOU),
            'todos' => array_slice(array_values(array_filter($this->todos->open($property->id), static fn (TodoItem $t): bool => $t->isDue($today))), 0, self::NEEDS_YOU),
        ];
    }

    /**
     * The newest run with what it did, and, for the run whose plan the screens show, how many
     * nights it would write.
     *
     * @return array{run: array<string, mixed>|null, would_write: int|null, writes_sent: int|null}
     */
    public function lastRun(Property $property): array
    {
        $recent = $this->lastPlan->recentRuns($property->id, 5);
        $run = $recent[0] ?? null;
        if ($run === null) {
            return ['run' => null, 'would_write' => null, 'writes_sent' => null];
        }
        $plan = $this->lastPlan->of($recent);
        $wouldWrite = $plan['run'] !== null && $plan['run']['id'] === $run['id']
            ? count(array_filter($plan['plan']->all(), static fn (PlannedNight $n): bool => $n->status === PlannedNight::WOULD_WRITE))
            : null;

        return [
            'run' => $run,
            'would_write' => $wouldWrite,
            'writes_sent' => is_numeric($run['summary']['write']['writes_sent'] ?? null) ? (int) $run['summary']['write']['writes_sent'] : null,
        ];
    }

    /**
     * The scheduled runs left today and the next full run, as local times of the property.
     *
     * @return array{full: DateTimeImmutable, light_left: list<DateTimeImmutable>}
     */
    public function nextRuns(Property $property): array
    {
        $rules = $this->ruleSets->current() ?? RuleSet::defaults();
        $zone = new DateTimeZone($property->timezone);
        $now = $this->clock->now()->setTimezone($zone);
        $today = $now->format('Y-m-d');
        $full = null;
        $light = [];
        foreach ($rules->schedule()->times() as [$kind, $time]) {
            $at = new DateTimeImmutable($today . ' ' . $time->toString(), $zone);
            if ($kind->value === 'full') {
                $full = $at > $now ? $at : $at->modify('+1 day');
            } elseif ($at > $now) {
                $light[] = $at;
            }
        }

        return ['full' => $full ?? $now->modify('+1 day'), 'light_left' => $light];
    }

    /**
     * @throws OutOfBoundsException when the property does not exist
     */
    public function property(string $propertyId): Property
    {
        return $this->properties->find($propertyId) ?? throw new OutOfBoundsException(sprintf('Unknown property "%s".', $propertyId));
    }

    /**
     * @return array<string, array<string, int>> room => night => units sold
     */
    private function soldUnits(DateRange $range): array
    {
        $sold = [];
        foreach ($this->bookings->overlapping($range->first(), $range->last()) as $booking) {
            if ($booking->roomCode === null || !$booking->status->occupies()) {
                continue;
            }
            foreach ($booking->nights() as $night) {
                $key = Days::key($night);
                $sold[$booking->roomCode][$key] = ($sold[$booking->roomCode][$key] ?? 0) + max(1, $booking->units);
            }
        }

        return $sold;
    }
}
