<?php

declare(strict_types=1);

namespace Ritmo\Domain\Relations\Coherence;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * The date items of a plan being built, in the order they were added, with at most one item per
 * room-night.
 */
final class PlanDraft
{
    /** @var array<string, DraftItem> */
    private array $items = [];

    /**
     * @param list<DraftItem> $items
     */
    public function __construct(array $items = [])
    {
        foreach ($items as $item) {
            $this->add($item);
        }
    }

    public function get(string $room, DateTimeImmutable $night): ?DraftItem
    {
        return $this->items[RoomNight::key($room, $night)] ?? null;
    }

    /**
     * @throws InvalidArgumentException when the room-night already has an item
     */
    public function add(DraftItem $item): void
    {
        if (isset($this->items[$item->key()])) {
            throw new InvalidArgumentException(sprintf('The plan already has an item for %s.', $item->key()));
        }
        $this->items[$item->key()] = $item;
    }

    public function remove(DraftItem $item): void
    {
        unset($this->items[$item->key()]);
    }

    /**
     * @return list<DraftItem>
     */
    public function items(): array
    {
        return array_values($this->items);
    }
}
