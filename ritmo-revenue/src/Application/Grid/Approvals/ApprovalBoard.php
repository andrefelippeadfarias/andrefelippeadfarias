<?php

declare(strict_types=1);

namespace Ritmo\Application\Grid\Approvals;

use DateTimeImmutable;
use OutOfBoundsException;
use Ritmo\Application\Approval\ApprovalRefused;
use Ritmo\Application\Approval\PartialApproval;
use Ritmo\Application\Approval\PlanEntry;
use Ritmo\Application\Grid\PlanIndex;
use Ritmo\Domain\Calendar\HolidayCalendar;
use Ritmo\Domain\Config\RuleSet;
use Ritmo\Domain\Model\Property;
use Ritmo\Domain\Model\RoomType;
use Ritmo\Domain\Relations\Relation;
use Ritmo\Domain\Support\Clock;
use Ritmo\Infrastructure\Db\Repository\PlanApprovalRepository;
use Ritmo\Infrastructure\Db\Repository\PlanItemRepository;
use Ritmo\Infrastructure\Db\Repository\PropertyRepository;
use Ritmo\Infrastructure\Db\Repository\RoomTypeRepository;
use Ritmo\Infrastructure\Db\Repository\RuleSetRepository;
use Ritmo\Infrastructure\Db\Repository\WaveHoldRepository;

/**
 * The read side of the Approvals screen (admin spec 5.4): the requests waiting, being written and
 * decided, each with its entries in money, segments, impact and relation pairs; the status a page
 * polls; and the closure of a selection. It reads in bulk (one statement per source and one per
 * request) and never writes: deciding goes through ApprovalDesk.
 */
final class ApprovalBoard
{
    /**
     * Per property: rooms, calendar, today and relations, read once per board.
     *
     * @var array<string, array{rooms: array<string, RoomType>, calendar: HolidayCalendar, today: DateTimeImmutable, hints: RelationHints}>
     */
    private array $contexts = [];

    public function __construct(
        private readonly PropertyRepository $properties,
        private readonly RoomTypeRepository $roomTypes,
        private readonly RuleSetRepository $ruleSets,
        private readonly PlanApprovalRepository $requests,
        private readonly PlanItemRepository $planItems,
        private readonly WaveHoldRepository $holds,
        private readonly Clock $clock,
    ) {
    }

    /**
     * @return list<RequestView> the requests waiting for a person, oldest first
     *
     * @throws OutOfBoundsException when the property does not exist
     */
    public function waiting(string $propertyId): array
    {
        return $this->views($propertyId, $this->requests->pending($propertyId));
    }

    /**
     * @return list<RequestView> approved and still being written
     */
    public function beingWritten(string $propertyId): array
    {
        return $this->views($propertyId, $this->requests->approved($propertyId));
    }

    /**
     * @return array{rows: list<RequestView>, total_known: int}
     */
    public function history(string $propertyId, int $page, int $perPage = 20): array
    {
        $rows = $this->requests->latest($propertyId, max(1, $page) * $perPage + 1);
        $slice = array_slice($rows, (max(1, $page) - 1) * $perPage, $perPage);

        return ['rows' => $this->views($propertyId, $slice), 'total_known' => count($rows)];
    }

    /**
     * @throws OutOfBoundsException when the request is not of the property
     */
    public function find(string $propertyId, int $requestId): RequestView
    {
        $row = $this->requests->find($requestId);
        if ($row === null || $row['property_id'] !== $propertyId) {
            throw new OutOfBoundsException(sprintf('No request %d for the property.', $requestId));
        }

        return $this->views($propertyId, [$row])[0];
    }

    /**
     * What a page waiting for a decided request polls: status, write run and counts.
     *
     * @return array{id: int, status: string, expires_at: DateTimeImmutable, expired: bool, write_run_id: int|null, entries: int, approved: int}|null
     */
    public function status(int $requestId): ?array
    {
        $row = $this->requests->find($requestId);
        if ($row === null) {
            return null;
        }

        return [
            'id' => $row['id'],
            'status' => $row['status'],
            'expires_at' => $row['expires_at'],
            'expired' => $row['status'] === PlanApprovalRepository::PENDING && $row['expires_at'] <= $this->clock->now(),
            'write_run_id' => $row['write_run_id'],
            'entries' => count($row['entries']),
            'approved' => count($row['approved_keys']),
        ];
    }

    /**
     * The closure of a selection over the groups, the relation pairs it splits and its impact.
     * Changes nothing.
     *
     * @param list<string> $keys
     *
     * @return array{approved: list<string>, added: list<string>, split: list<array{relation: string, night: string, rooms: list<string>}>, impact: Impact}
     *
     * @throws ApprovalRefused       when a key is not an entry of the request
     * @throws OutOfBoundsException when the request is not of the property
     */
    public function selection(string $propertyId, int $requestId, array $keys): array
    {
        $row = $this->requests->find($requestId);
        if ($row === null || $row['property_id'] !== $propertyId) {
            throw new OutOfBoundsException(sprintf('No request %d for the property.', $requestId));
        }
        $entries = array_map(static fn (array $e): PlanEntry => PlanEntry::fromArray($e), array_values(array_filter($row['entries'], 'is_array')));
        $closed = PartialApproval::close($entries, $keys);
        $approved = $closed->approved;
        [$rows, $hints] = $this->rows($propertyId, $row);
        $chosen = array_flip($approved);

        return [
            'approved' => $approved,
            'added' => $closed->added,
            'split' => $hints->split($rows, $approved),
            'impact' => Impact::of(array_values(array_filter($rows, static fn (EntryRow $r): bool => isset($chosen[$r->key])))),
        ];
    }

    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return list<RequestView>
     */
    private function views(string $propertyId, array $rows): array
    {
        if ($rows === []) {
            return [];
        }

        return array_map(function (array $row) use ($propertyId): RequestView {
            [$entries, $hints, $order] = $this->rows($propertyId, $row);

            return $this->view($row, $entries, $hints->pairs($entries), $order);
        }, $rows);
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array{0: list<EntryRow>, 1: RelationHints, 2: list<string>}
     */
    private function rows(string $propertyId, array $row): array
    {
        $context = $this->context($propertyId);
        $plan = $row['run_id'] === null ? PlanIndex::empty() : (new PlanIndex($this->planItems->forRun((int) $row['run_id'])))->withListing($context['rooms']);
        $builder = new RowBuilder($plan, $context['rooms'], $context['calendar'], $context['today'], $this->holds->open($propertyId));
        $stored = array_values(array_filter($row['entries'], 'is_array'));
        $first = $builder->rows($stored);
        $linked = [];
        foreach ($context['hints']->pairs($first) as $pair) {
            foreach ($first as $entry) {
                if ($entry->night !== null && $entry->night->format('Y-m-d') === $pair['night'] && in_array($entry->room, $pair['rooms'], true)) {
                    $linked[] = $entry->key;
                }
            }
        }

        return [$linked === [] ? $first : $builder->rows($stored, $linked), $context['hints'], array_keys($context['rooms'])];
    }

    /**
     * @param array<string, mixed>                                              $row
     * @param list<EntryRow>                                                    $entries
     * @param list<array{relation: string, night: string, rooms: list<string>}> $pairs
     * @param list<string>                                                      $order
     */
    private function view(array $row, array $entries, array $pairs, array $order): RequestView
    {
        $nights = array_values(array_filter($entries, static fn (EntryRow $e): bool => $e->night !== null));
        $byRoom = [];
        foreach (Segmenter::segments($nights, $order) as $segment) {
            $byRoom[$segment->room][] = $segment;
        }

        return new RequestView(
            (int) $row['id'],
            (string) $row['kind'],
            (string) $row['status'],
            $row['plan_day'],
            $row['created_at'],
            $row['expires_at'],
            (string) $row['expiry_reason'],
            $row['status'] === PlanApprovalRepository::PENDING && $row['expires_at'] <= $this->clock->now(),
            $row['run_id'],
            $row['write_run_id'],
            $row['decided_by'],
            $row['decided_at'],
            (string) $row['note'],
            count($entries),
            $byRoom,
            array_values(array_filter($entries, static fn (EntryRow $e): bool => $e->night === null)),
            Impact::of($entries),
            $pairs,
        );
    }

    /**
     * @return array{rooms: array<string, RoomType>, calendar: HolidayCalendar, today: DateTimeImmutable, hints: RelationHints}
     */
    private function context(string $propertyId): array
    {
        if (isset($this->contexts[$propertyId])) {
            return $this->contexts[$propertyId];
        }
        $property = $this->properties->find($propertyId) ?? throw new OutOfBoundsException(sprintf('Unknown property "%s".', $propertyId));
        $rules = $this->ruleSets->current() ?? RuleSet::defaults();
        $rooms = [];
        foreach ($this->roomTypes->forProperty($property->id) as $room) {
            $rooms[$room->code] = $room;
        }
        $calendar = new HolidayCalendar($rules);
        $today = $this->clock->today($property->timezone);

        return $this->contexts[$propertyId] = [
            'rooms' => $rooms,
            'calendar' => $calendar,
            'today' => $today,
            'hints' => new RelationHints(self::relations($rules, $property), array_map(static fn (RoomType $r): array => $r->tags, $rooms), $calendar->nightType(...), $today),
        ];
    }

    /**
     * @return list<Relation>
     */
    private static function relations(RuleSet $rules, Property $property): array
    {
        return array_values(array_filter($rules->relations()->relations, static fn (Relation $r): bool => $r->propertyId === $property->id));
    }
}
