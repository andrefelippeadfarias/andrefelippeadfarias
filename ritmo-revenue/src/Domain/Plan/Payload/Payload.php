<?php

declare(strict_types=1);

namespace Ritmo\Domain\Plan\Payload;

/**
 * What a validated plan writes to the pricing system, as data: per listing, the date rules to
 * delete, the date rules to write and the listing changes. Nothing here sends anything.
 */
final class Payload
{
    /**
     * @param list<RoomCall>     $deletions one call per listing, dates in plan order
     * @param list<RoomCall>     $writes    one call per listing, overrides in plan order
     * @param list<ListingWrite> $listing
     */
    public function __construct(
        public readonly array $deletions,
        public readonly array $writes,
        public readonly array $listing,
    ) {
    }

    public function isEmpty(): bool
    {
        return $this->deletions === [] && $this->writes === [] && $this->listing === [];
    }

    /**
     * @return array{deletions: list<array<string, mixed>>, writes: list<array<string, mixed>>, listing: list<array<string, mixed>>}
     */
    public function toArray(): array
    {
        return [
            'deletions' => array_map(static fn (RoomCall $c): array => $c->toArray(), $this->deletions),
            'writes' => array_map(static fn (RoomCall $c): array => $c->toArray(), $this->writes),
            'listing' => array_map(static fn (ListingWrite $w): array => $w->toArray(), $this->listing),
        ];
    }
}
