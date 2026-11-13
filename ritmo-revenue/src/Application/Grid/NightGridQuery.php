<?php

declare(strict_types=1);

namespace Ritmo\Application\Grid;

use DateTimeImmutable;
use OutOfBoundsException;
use Ritmo\Domain\Calendar\HolidayCalendar;
use Ritmo\Domain\Config\RuleSet;
use Ritmo\Domain\Model\InventoryNight;
use Ritmo\Domain\Model\Property;
use Ritmo\Domain\Model\Restriction;
use Ritmo\Domain\Model\RoomType;
use Ritmo\Domain\Support\Clock;
use Ritmo\Domain\Support\DateRange;
use Ritmo\Domain\Support\Days;
use Ritmo\Infrastructure\Db\Repository\AlertRepository;
use Ritmo\Infrastructure\Db\Repository\BookingRepository;
use Ritmo\Infrastructure\Db\Repository\ChangeLogRepository;
use Ritmo\Infrastructure\Db\Repository\DetectedFreezeRepository;
use Ritmo\Infrastructure\Db\Repository\InventorySnapshotRepository;
use Ritmo\Infrastructure\Db\Repository\OrderRepository;
use Ritmo\Infrastructure\Db\Repository\PropertyRepository;
use Ritmo\Infrastructure\Db\Repository\RoomTypeRepository;
use Ritmo\Infrastructure\Db\Repository\RuleSetRepository;

/**
 * Reads the Nights grid of a property from the plugin's own tables, in bulk: one statement per
 * source and one per room of the page for the PMS calendar, whatever the number of nights
 * (admin spec 9.2). Nothing here calls an external system or writes.
 */
final class NightGridQuery
{
    public const ROOMS_PER_PAGE = 12;

    public const MAX_NIGHTS = 90;

    /** Unresolved alerts and recent writes read to mark the cells. */
    private const MARK_ROWS = 1000;

    public function __construct(
        private readonly PropertyRepository $properties,
        private readonly RoomTypeRepository $roomTypes,
        private readonly RuleSetRepository $ruleSets,
        private readonly InventorySnapshotRepository $snapshots,
        private readonly BookingRepository $bookings,
        private readonly LastPlan $lastPlan,
        private readonly DetectedFreezeRepository $freezes,
        private readonly OrderRepository $orders,
        private readonly AlertRepository $alerts,
        private readonly ChangeLogRepository $changes,
        private readonly Clock $clock,
    ) {
    }

    /**
     * @param list<string> $roomCodes only these rooms (empty: every room)
     *
     * @throws OutOfBoundsException when the property does not exist
     */
    public function window(string $propertyId, DateTimeImmutable $from, int $nights, array $roomCodes, NightFilter $filter, int $roomPage = 1): NightGrid
    {
        $property = $this->properties->find($propertyId) ?? throw new OutOfBoundsException(sprintf('Unknown property "%s".', $propertyId));
        $chosen = array_values(array_filter($this->roomTypes->forProperty($property->id), static fn (RoomType $r): bool => $roomCodes === [] || in_array($r->code, $roomCodes, true)));
        $offset = (max(1, $roomPage) - 1) * self::ROOMS_PER_PAGE;
        $offset = $offset >= count($chosen) ? 0 : $offset;
        $rooms = array_slice($chosen, $offset, self::ROOMS_PER_PAGE);
        $range = DateRange::nights(Days::normalize($from), max(1, min(self::MAX_NIGHTS, $nights)));
        $rules = $this->ruleSets->current() ?? RuleSet::defaults();
        $context = $this->context($property, $range, $rules);

        $cells = [];
        foreach ($rooms as $room) {
            $calendar = $this->snapshots->latest($room->code, $range->first(), $range->last());
            foreach ($range as $night) {
                $cell = $this->cell($room, $night, $calendar[Days::key($night)] ?? null, $context);
                $cells[$room->code][Days::key($night)] = $filter->accepts($cell) ? $cell : null;
            }
        }

        return new NightGrid($property, $rooms, count($chosen), $offset, iterator_to_array($range, false), $cells, $context->holidays, $context->run, $filter);
    }

    /**
     * One cell outside a grid (the night detail), with the same sources.
     *
     * @throws OutOfBoundsException when the property or the room does not exist
     */
    public function one(string $propertyId, string $roomCode, DateTimeImmutable $night): NightCell
    {
        $property = $this->properties->find($propertyId) ?? throw new OutOfBoundsException(sprintf('Unknown property "%s".', $propertyId));
        $room = $this->roomTypes->find($roomCode);
        if ($room === null || $room->propertyId !== $property->id) {
            throw new OutOfBoundsException(sprintf('Unknown room "%s".', $roomCode));
        }
        $range = DateRange::nights(Days::normalize($night), 1);
        $context = $this->context($property, $range, $this->ruleSets->current() ?? RuleSet::defaults());
        $calendar = $this->snapshots->latest($room->code, $range->first(), $range->last());

        return $this->cell($room, $range->first(), $calendar[Days::key($range->first())] ?? null, $context);
    }

    private function context(Property $property, DateRange $range, RuleSet $rules): GridContext
    {
        $now = $this->clock->now();
        $last = $this->lastPlan->of($this->lastPlan->recentRuns($property->id));
        $calendar = new HolidayCalendar($rules);
        $holidays = [];
        foreach ($range as $night) {
            $holiday = $calendar->holidayOn($night);
            if ($holiday !== null) {
                $holidays[Days::key($night)] = $holiday->name;
            }
        }
        $marks = new NightMarks(
            $this->bookings->overlapping($range->first(), $range->last()),
            $this->freezes->unreleased($property->id),
            $this->orders->book($property->id, $range->first()),
            $this->alerts->unresolved($property->id, self::MARK_ROWS),
            $this->changes->latest($property->id, self::MARK_ROWS),
            $now,
        );

        return new GridContext($calendar, $holidays, $last['plan'], $last['run'], $marks, $this->clock->today($property->timezone));
    }

    private function cell(RoomType $room, DateTimeImmutable $night, ?InventoryNight $calendar, GridContext $context): NightCell
    {
        $key = Days::key($night);
        $freeze = $context->marks->freeze($room->code, $key);
        $restriction = $calendar?->restriction ?? Restriction::None;

        return new NightCell(
            $room->code,
            $night,
            $context->calendar->nightType($night)->value,
            $context->holidays[$key] ?? '',
            Days::diff($context->today, $night),
            $context->marks->sold($room->code, $key),
            $room->units,
            $restriction === Restriction::Blackout || ($calendar !== null && $calendar->available <= 0 && $context->marks->sold($room->code, $key) < $room->units),
            $context->plan->price($room->code, $key) ?? $calendar?->pmsPrice,
            $calendar?->pmsPrice,
            $context->plan->night($room->code, $key),
            $context->plan->trace($room->code, $key),
            $freeze !== null,
            $freeze?->frozenUntil,
            $context->marks->orders($room->code, $night),
            $context->marks->alerts($room->code, $key),
            $context->marks->lastWritten($room->code, $key),
            $calendar?->minStay,
            $restriction->value,
        );
    }
}
