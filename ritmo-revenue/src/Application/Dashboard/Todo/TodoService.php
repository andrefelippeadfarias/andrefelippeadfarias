<?php

declare(strict_types=1);

namespace Ritmo\Application\Dashboard\Todo;

use DateTimeImmutable;
use InvalidArgumentException;
use OutOfBoundsException;
use Ritmo\Domain\Support\Clock;
use Ritmo\Domain\Support\Days;

/**
 * The to-do list: what people add, close and reopen, and the plugin's "press Sync Now" item after
 * a write that touched tonight or tomorrow. The caller checks the capability, the nonce and the
 * privacy of the texts first.
 */
final class TodoService
{
    public const NOTE = 'note';

    public const MAX_TITLE = 190;

    public const MAX_DETAILS = 1000;

    public function __construct(private readonly TodoStore $store, private readonly Clock $clock)
    {
    }

    /**
     * @throws InvalidArgumentException on an empty or too long text
     */
    public function add(string $propertyId, string $title, string $details, ?DateTimeImmutable $dueOn, int $userId): int
    {
        if (trim($title) === '' || mb_strlen($title) > self::MAX_TITLE || mb_strlen($details) > self::MAX_DETAILS) {
            throw new InvalidArgumentException('A to-do item needs a title of at most 190 characters and details of at most 1000.');
        }

        return $this->store->add(new TodoItem($propertyId, TodoItem::PERSON, self::NOTE, trim($title), trim($details), $dueOn, createdBy: $userId), $this->clock->now());
    }

    /**
     * @throws OutOfBoundsException when the item does not exist or is not of the property
     */
    public function close(string $propertyId, int $id, int $userId): void
    {
        $this->belongs($propertyId, $id);
        $this->store->setStatus($id, TodoItem::DONE, $userId, $this->clock->now());
    }

    /**
     * @throws OutOfBoundsException when the item does not exist or is not of the property
     */
    public function reopen(string $propertyId, int $id, int $userId): void
    {
        $this->belongs($propertyId, $id);
        $this->store->setStatus($id, TodoItem::OPEN, $userId, $this->clock->now());
    }

    /**
     * After a write that changed tonight's or tomorrow's price of a room: once per room and day.
     */
    public function syncNow(string $propertyId, string $roomCode, DateTimeImmutable $today): int
    {
        return $this->store->add(new TodoItem(
            $propertyId,
            TodoItem::PLUGIN,
            TodoGenerator::SYNC_NOW,
            sprintf('Press Sync Now in the pricing system for room %s.', $roomCode),
            dueOn: $today,
            roomCode: $roomCode,
            night: $today,
            dedupeKey: sprintf('%s:%s:%s:%s', TodoGenerator::SYNC_NOW, $propertyId, $roomCode, Days::key($today)),
            params: ['room' => $roomCode],
        ), $this->clock->now(), true);
    }

    /**
     * @return array{open: list<TodoItem>, done: list<TodoItem>}
     */
    public function list(string $propertyId): array
    {
        return ['open' => $this->store->open($propertyId), 'done' => $this->store->done($propertyId)];
    }

    /**
     * @throws OutOfBoundsException
     */
    private function belongs(string $propertyId, int $id): void
    {
        $item = $this->store->find($id);
        if ($item === null || ($item->propertyId !== '' && $item->propertyId !== $propertyId)) {
            throw new OutOfBoundsException(sprintf('No to-do item %d for the property.', $id));
        }
    }
}
