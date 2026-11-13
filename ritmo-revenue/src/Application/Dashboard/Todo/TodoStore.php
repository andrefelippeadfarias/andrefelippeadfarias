<?php

declare(strict_types=1);

namespace Ritmo\Application\Dashboard\Todo;

use DateTimeImmutable;

/**
 * Storage of the to-do items (pending_items; implemented in Infrastructure\Db\Query\Screens).
 */
interface TodoStore
{
    /**
     * Adds the item unless one with its dedupe key exists (open only, or in any status when
     * $once is true); returns the id of the item added or of the one that already was there.
     */
    public function add(TodoItem $item, DateTimeImmutable $now, bool $once = false): int;

    public function find(int $id): ?TodoItem;

    /**
     * @return list<TodoItem> open items by due date then creation; with a property, only its items and the site-wide ones
     */
    public function open(?string $propertyId, int $limit = 200): array;

    /**
     * @return list<TodoItem> closed items, newest first
     */
    public function done(?string $propertyId, int $limit = 50): array;

    public function setStatus(int $id, string $status, int $userId, DateTimeImmutable $now): bool;

    /**
     * Closes the open plugin items of these dedupe keys (their condition ended).
     *
     * @param list<string> $keys
     */
    public function closeKeys(array $keys, DateTimeImmutable $now): int;

    /**
     * @return list<string> dedupe keys of the open plugin items of a kind
     */
    public function openKeys(string $kind): array;

    public function countDue(?string $propertyId, DateTimeImmutable $today): int;
}
