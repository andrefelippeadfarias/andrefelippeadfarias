<?php

declare(strict_types=1);

namespace Ritmo\Application\Write\Schedule;

use InvalidArgumentException;
use Ritmo\Contracts\WriteOperation;
use Ritmo\Contracts\WriteRequest;

/**
 * One call of a write run, in its place in the schedule: what it sends (the body's entries), to
 * which targets and nights, and the group it belongs to (a deletion and the rewrite of the same
 * nights share a group key and are adjacent).
 *
 * Payload by operation: OverridesWrite: list of date rules in the API's fields; OverridesDelete:
 * list of YYYY-MM-DD dates; ListingUpdate: field => amount; PmsCalendar: list of ranges
 * (CalendarChange::range()); Recalculation: empty.
 */
final class WriteStep
{
    /**
     * @param array<string, list<string>> $nights      target (listing id or PMS room id) => nights
     * @param list<mixed>|array<string, float> $payload
     * @param list<CalendarChange>        $changes     the PMS changes of a PmsCalendar step
     *
     * @throws InvalidArgumentException on a step without a target
     */
    public function __construct(
        public readonly int $number,
        public readonly WriteOperation $operation,
        public readonly string $roomCode,
        public readonly array $nights,
        public readonly array $payload,
        public readonly string $label,
        public readonly string $groupKey = '',
        public readonly array $changes = [],
    ) {
        if ($nights === []) {
            throw new InvalidArgumentException('A write step has a target.');
        }
    }

    /**
     * The same step at another position.
     */
    public function at(int $number): self
    {
        return new self($number, $this->operation, $this->roomCode, $this->nights, $this->payload, $this->label, $this->groupKey, $this->changes);
    }

    /**
     * What the token must allow for this step (operation, targets, nights, fields and the entries
     * with their values).
     */
    public function request(): WriteRequest
    {
        return new WriteRequest($this->operation, $this->nights, $this->fields(), WriteRequest::entriesOf($this->payload));
    }

    public function target(): string
    {
        return (string) array_key_first($this->nights);
    }

    /**
     * The room code of each target: a PMS block may carry several rooms (one POST for the same
     * night of every room), each under its own PMS room id; every other step has one room.
     *
     * @return array<string, string> target => room code
     */
    public function targetRooms(): array
    {
        $rooms = [];
        foreach (array_keys($this->nights) as $target) {
            $rooms[(string) $target] = $this->roomCode;
        }
        foreach ($this->changes as $change) {
            $rooms[$change->roomId] = $change->roomCode;
        }

        return $rooms;
    }

    /**
     * @return list<string> every night of the step, all targets together
     */
    public function dates(): array
    {
        $all = array_merge(...array_values($this->nights));
        $all = array_values(array_unique($all));
        sort($all);

        return $all;
    }

    public function isGroupRewrite(): bool
    {
        return $this->groupKey !== '' && $this->operation === WriteOperation::OverridesWrite;
    }

    /**
     * Names of the fields the body sets: the same rule the connectors use to describe a request.
     *
     * @return list<string>
     */
    public function fields(): array
    {
        $fields = [];
        if ($this->operation === WriteOperation::ListingUpdate) {
            return array_keys($this->payload);
        }
        if ($this->operation !== WriteOperation::OverridesWrite && $this->operation !== WriteOperation::PmsCalendar) {
            return [];
        }
        foreach ($this->payload as $entry) {
            foreach ($entry as $field => $value) {
                if ($field === 'channels') {
                    foreach (array_keys($value) as $channel) {
                        $fields['channels.' . $channel] = true;
                    }
                } elseif (!in_array($field, ['date', 'roomId', 'from', 'to'], true)) {
                    $fields[$field] = true;
                }
            }
        }

        return array_keys($fields);
    }
}
