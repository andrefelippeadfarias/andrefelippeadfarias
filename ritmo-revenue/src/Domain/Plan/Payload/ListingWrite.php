<?php

declare(strict_types=1);

namespace Ritmo\Domain\Plan\Payload;

/**
 * The listing amounts of one listing to change (base, min, max), sent plan.
 */
final class ListingWrite
{
    /**
     * @param array<string, float> $fields field => amount
     */
    public function __construct(
        public readonly string $roomCode,
        public readonly string $listingRef,
        public readonly string $pms,
        public readonly array $fields,
    ) {
    }

    /**
     * @return array<string, float|string>
     */
    public function toArray(): array
    {
        return ['listing_id' => $this->listingRef, 'pms' => $this->pms] + $this->fields;
    }
}
