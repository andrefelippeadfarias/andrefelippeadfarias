<?php

declare(strict_types=1);

namespace Ritmo\Application\Write\Resume;

use InvalidArgumentException;
use Ritmo\Domain\Model\RoomType;
use Ritmo\Domain\Plan\PlanItem;
use Ritmo\Domain\Support\Days;
use Ritmo\Domain\Support\Num;

/**
 * A deletion that went out without the rewrite of its group: the nights were left without their
 * rule. Its rewrite is the first work of the resume, as plan items (reason "repair_group") that go
 * through the validator and the gate like any other: a repair can be refused (tonight never rises).
 */
final class BrokenGroup
{
    public const REASON = 'repair_group';

    /**
     * @param list<array<string, string>> $rewrite the date rules of the rewrite, in the API's fields
     */
    public function __construct(
        public readonly string $groupKey,
        public readonly string $roomCode,
        public readonly string $listing,
        public readonly array $rewrite,
    ) {
    }

    /**
     * @return array{group: string, room: string, listing: string, rewrite: list<array<string, string>>}
     */
    public function toArray(): array
    {
        return ['group' => $this->groupKey, 'room' => $this->roomCode, 'listing' => $this->listing, 'rewrite' => $this->rewrite];
    }

    /**
     * @param array<string, mixed> $data as toArray() gives it
     *
     * @throws InvalidArgumentException when a field is missing
     */
    public static function fromArray(array $data): self
    {
        foreach (['group', 'room', 'listing'] as $key) {
            if (!is_string($data[$key] ?? null) || $data[$key] === '') {
                throw new InvalidArgumentException(sprintf('A broken group needs "%s".', $key));
            }
        }
        if (!is_array($data['rewrite'] ?? null) || !array_is_list($data['rewrite'])) {
            throw new InvalidArgumentException('A broken group needs the list of its rewrite.');
        }

        return new self($data['group'], $data['room'], $data['listing'], $data['rewrite']);
    }

    /**
     * The rewrite as plan items, nights before today left out (the past is never written).
     *
     * @return list<PlanItem>
     *
     * @throws InvalidArgumentException when the room is not the group's
     */
    public function repairItems(RoomType $room, string $today): array
    {
        if ($room->code !== $this->roomCode) {
            throw new InvalidArgumentException('The repair needs the room of the group.');
        }
        $items = [];
        foreach ($this->rewrite as $entry) {
            if ($entry['date'] < $today) {
                continue;
            }
            $items[] = new PlanItem(
                $this->roomCode,
                Days::date($entry['date']),
                isset($entry['min_price']) ? Num::percentToAmount((float) $entry['min_price'], $room->base, 2) : null,
                isset($entry['max_price']) ? Num::percentToAmount((float) $entry['max_price'], $room->base, 2) : null,
                isset($entry['min_stay']) ? (int) $entry['min_stay'] : null,
                self::REASON,
                ['group' => $this->groupKey],
                null,
                $entry['check_in'] ?? null,
                $entry['check_out'] ?? null,
                reasonText: $entry['reason'] ?? self::REASON,
            );
        }

        return $items;
    }
}
