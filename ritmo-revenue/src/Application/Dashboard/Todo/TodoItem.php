<?php

declare(strict_types=1);

namespace Ritmo\Application\Dashboard\Todo;

use DateTimeImmutable;

/**
 * Something only a person can do (admin spec 5.12): added by the plugin, which closes it when its
 * condition ends, or by a person. Texts typed by people passed the privacy guard first; the
 * plugin's items carry a kind and parameters, and the screen writes their sentence.
 */
final class TodoItem
{
    public const PLUGIN = 'plugin';
    public const PERSON = 'person';

    public const OPEN = 'open';
    public const DONE = 'done';

    /**
     * @param array<string, string|int> $params facts of a plugin item (room, provider), never guest data
     */
    public function __construct(
        public readonly string $propertyId,
        public readonly string $source,
        public readonly string $kind,
        public readonly string $title,
        public readonly string $details = '',
        public readonly ?DateTimeImmutable $dueOn = null,
        public readonly string $roomCode = '',
        public readonly ?DateTimeImmutable $night = null,
        public readonly string $dedupeKey = '',
        public readonly array $params = [],
        public readonly int $createdBy = 0,
        public readonly string $status = self::OPEN,
        public readonly ?DateTimeImmutable $createdAt = null,
        public readonly ?DateTimeImmutable $closedAt = null,
        public readonly ?int $closedBy = null,
        public readonly ?int $id = null,
    ) {
    }

    public function isOpen(): bool
    {
        return $this->status === self::OPEN;
    }

    public function isDue(DateTimeImmutable $today): bool
    {
        return $this->isOpen() && ($this->dueOn === null || $this->dueOn <= $today);
    }
}
