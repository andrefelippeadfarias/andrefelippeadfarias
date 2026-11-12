<?php

declare(strict_types=1);

namespace Ritmo\Domain\Plan\Payload;

use DateTimeImmutable;
use Ritmo\Domain\Support\Days;

/**
 * One call of the payload, in writing order: a part of the date rules of one listing (tonight,
 * tomorrow or one chunk of the rest, "k/n") or the listing changes of one listing.
 */
final class Slice
{
    public const TONIGHT = 'D0';
    public const TOMORROW = 'D1';
    public const LISTING = 'listing';

    /**
     * @param int $number position in the writing order, from 1
     */
    public function __construct(
        public readonly int $number,
        public readonly CallKind $kind,
        public readonly string $label,
        public readonly RoomCall|ListingWrite $request,
    ) {
    }

    public function roomCode(): string
    {
        return $this->request->roomCode;
    }

    public function first(): ?DateTimeImmutable
    {
        $dates = $this->dates();

        return $dates === [] ? null : min($dates);
    }

    public function last(): ?DateTimeImmutable
    {
        $dates = $this->dates();

        return $dates === [] ? null : max($dates);
    }

    /**
     * @return list<DateTimeImmutable>
     */
    public function dates(): array
    {
        if (!$this->request instanceof RoomCall) {
            return [];
        }
        $call = $this->request;

        return array_map(static fn (OverrideWrite|DateTimeImmutable $e): DateTimeImmutable => $call->dateOf($e), $call->entries);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $first = $this->first();
        $last = $this->last();

        return [
            'number' => $this->number,
            'kind' => $this->kind->value,
            'room' => $this->roomCode(),
            'label' => $this->label,
            'from' => $first === null ? null : Days::key($first),
            'to' => $last === null ? null : Days::key($last),
            'dates' => count($this->dates()),
            'call' => $this->request->toArray(),
        ];
    }
}
