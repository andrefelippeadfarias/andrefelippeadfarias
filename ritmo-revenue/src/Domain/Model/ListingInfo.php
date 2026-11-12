<?php

declare(strict_types=1);

namespace Ritmo\Domain\Model;

use InvalidArgumentException;

/**
 * A listing as the pricing system reports it: live values, not the plugin's own configuration.
 */
final class ListingInfo
{
    /**
     * @param string      $ref      id of the listing in the pricing system (RoomType::$pricingRef)
     * @param float|null  $min      minimum price of the listing; null when not reported
     * @param float|null  $base     base price of the listing; null when not reported
     * @param float|null  $max      maximum price of the listing; null when not reported
     * @param string|null $currency ISO 4217 code; null when not reported
     *
     * @throws InvalidArgumentException on an empty reference
     */
    public function __construct(
        public readonly string $ref,
        public readonly string $name,
        public readonly ?float $min,
        public readonly ?float $base,
        public readonly ?float $max,
        public readonly ?string $currency,
        public readonly SyncStatus $sync,
    ) {
        if ($ref === '') {
            throw new InvalidArgumentException('A listing needs its reference.');
        }
    }
}
