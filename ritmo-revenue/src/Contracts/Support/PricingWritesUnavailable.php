<?php

declare(strict_types=1);

namespace Ritmo\Contracts\Support;

use Ritmo\Contracts\Exception\NotAvailableInThisMilestone;
use Ritmo\Contracts\WriteToken;

/**
 * The write half of PricingConnector for a read-only plugin: every method throws.
 *
 * Every PricingConnector implementation uses this trait until the milestone that enables
 * writing, so "no code path writes to the pricing system" is true by construction and checked
 * in one place.
 */
trait PricingWritesUnavailable
{
    public function requestRecalculation(WriteToken $token, string $listingRef): void
    {
        throw NotAvailableInThisMilestone::forOperation('PricingConnector::requestRecalculation');
    }

    /**
     * @param list<array<string, mixed>> $overrides
     */
    public function writeOverrides(WriteToken $token, string $listingRef, array $overrides): void
    {
        throw NotAvailableInThisMilestone::forOperation('PricingConnector::writeOverrides');
    }

    /**
     * @param list<\DateTimeImmutable> $dates
     */
    public function deleteOverrides(WriteToken $token, string $listingRef, array $dates): void
    {
        throw NotAvailableInThisMilestone::forOperation('PricingConnector::deleteOverrides');
    }

    /**
     * @param array<string, float> $fields
     */
    public function updateListing(WriteToken $token, string $listingRef, array $fields): void
    {
        throw NotAvailableInThisMilestone::forOperation('PricingConnector::updateListing');
    }
}
