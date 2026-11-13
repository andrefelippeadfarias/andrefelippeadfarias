<?php

declare(strict_types=1);

namespace Ritmo\Application\Dashboard\Changes;

use DateTimeImmutable;

/**
 * The filters of the Change log: room, nights, when it changed, which system, who allowed it
 * and "only not undone". An empty value does not filter.
 */
final class ChangeFilter
{
    public const SYSTEMS = ['', 'pricing', 'pms', 'listing'];

    public const WHO = ['', 'autonomous', 'person', 'undo'];

    public function __construct(
        public readonly string $propertyId,
        public readonly string $room = '',
        public readonly ?DateTimeImmutable $nightFrom = null,
        public readonly ?DateTimeImmutable $nightTo = null,
        public readonly ?DateTimeImmutable $changedFrom = null,
        public readonly ?DateTimeImmutable $changedTo = null,
        public readonly string $system = '',
        public readonly string $who = '',
        public readonly bool $notUndone = false,
    ) {
    }

    /**
     * @return array<string, string>
     */
    public function query(): array
    {
        return array_filter([
            'room' => $this->room,
            'night_from' => $this->nightFrom?->format('Y-m-d') ?? '',
            'night_to' => $this->nightTo?->format('Y-m-d') ?? '',
            'changed_from' => $this->changedFrom?->format('Y-m-d') ?? '',
            'changed_to' => $this->changedTo?->format('Y-m-d') ?? '',
            'system' => $this->system,
            'who' => $this->who,
            'not_undone' => $this->notUndone ? '1' : '',
        ], static fn (string $v): bool => $v !== '');
    }
}
