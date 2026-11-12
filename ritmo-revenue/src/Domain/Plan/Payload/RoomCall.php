<?php

declare(strict_types=1);

namespace Ritmo\Domain\Plan\Payload;

use DateTimeImmutable;
use InvalidArgumentException;
use Ritmo\Domain\Support\Days;

/**
 * The date rules of one listing in one call: the overrides to write, or the dates whose rules are
 * deleted.
 */
final class RoomCall
{
    /** @var list<OverrideWrite|DateTimeImmutable> */
    public readonly array $entries;

    /**
     * @param list<OverrideWrite|DateTimeImmutable> $entries overrides (a write) or dates (a deletion)
     *
     * @throws InvalidArgumentException on an entry of the other kind
     */
    public function __construct(
        public readonly CallKind $kind,
        public readonly string $roomCode,
        public readonly string $listingRef,
        public readonly string $pms,
        array $entries,
    ) {
        foreach ($entries as $entry) {
            if ($kind === CallKind::Write ? !$entry instanceof OverrideWrite : !$entry instanceof DateTimeImmutable) {
                throw new InvalidArgumentException('A write holds overrides and a deletion holds dates.');
            }
        }
        $this->entries = array_values($entries);
    }

    public function dateOf(OverrideWrite|DateTimeImmutable $entry): DateTimeImmutable
    {
        return $entry instanceof OverrideWrite ? $entry->date : $entry;
    }

    /**
     * The same call with other entries of its listing.
     *
     * @param list<OverrideWrite|DateTimeImmutable> $entries
     */
    public function with(array $entries): self
    {
        return new self($this->kind, $this->roomCode, $this->listingRef, $this->pms, $entries);
    }

    /**
     * @return array{listing_id: string, pms: string, overrides: list<array<string, string>>}
     */
    public function toArray(): array
    {
        return [
            'listing_id' => $this->listingRef,
            'pms' => $this->pms,
            'overrides' => array_map(
                static fn (OverrideWrite|DateTimeImmutable $e): array => $e instanceof OverrideWrite ? $e->toArray() : ['date' => Days::key($e)],
                $this->entries,
            ),
        ];
    }
}
