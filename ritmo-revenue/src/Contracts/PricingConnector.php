<?php

declare(strict_types=1);

namespace Ritmo\Contracts;

use DateTimeImmutable;
use Ritmo\Contracts\Exception\ConnectorException;
use Ritmo\Contracts\Exception\NotAvailableInThisMilestone;
use Ritmo\Domain\Model\DateOverride;
use Ritmo\Domain\Model\ListingInfo;
use Ritmo\Domain\Model\PriceNight;

/**
 * A dynamic pricing system: computes the nightly prices and pushes them to the PMS.
 *
 * Dates are date-only values (midnight UTC, see Days). $from and $to are nights, BOTH INCLUSIVE.
 * $listingRef is the listing id in the pricing system (RoomType::$pricingRef).
 */
interface PricingConnector
{
    /**
     * Stable id of the connector ("pricelabs").
     */
    public function id(): string;

    /**
     * Checks credentials and reachability with a cheap read. Never throws: failures come back
     * as a status with ok = false.
     */
    public function testConnection(): ConnectionStatus;

    /**
     * Every listing of the account with its min/base/max and push state.
     *
     * @return list<ListingInfo>
     *
     * @throws ConnectorException
     */
    public function listListings(): array;

    /**
     * The prices and occupancy of a listing for the nights [$from, $to], as of its last
     * recalculation.
     *
     * @return list<PriceNight> in ascending date order
     *
     * @throws ConnectorException
     */
    public function readPrices(string $listingRef, DateTimeImmutable $from, DateTimeImmutable $to): array;

    /**
     * The date-specific overrides of a listing for the nights [$from, $to].
     *
     * @return list<DateOverride> in ascending date order; nights without an override are absent
     *
     * @throws ConnectorException
     */
    public function readOverrides(string $listingRef, DateTimeImmutable $from, DateTimeImmutable $to): array;

    /**
     * Asks the pricing system to recalculate a listing (a metered operation).
     *
     * @throws NotAvailableInThisMilestone always, while the plugin is read-only
     * @throws ConnectorException
     */
    public function requestRecalculation(WriteToken $token, string $listingRef): void;

    /**
     * Creates or replaces date-specific overrides of a listing.
     *
     * The shape of $overrides is provisional: it is fixed together with the validated plan in
     * the milestone that enables writing.
     *
     * @param list<array<string, mixed>> $overrides
     *
     * @throws NotAvailableInThisMilestone always, while the plugin is read-only
     * @throws ConnectorException
     */
    public function writeOverrides(WriteToken $token, string $listingRef, array $overrides): void;

    /**
     * Deletes the date-specific overrides of a listing on the given nights.
     *
     * @param list<DateTimeImmutable> $dates
     *
     * @throws NotAvailableInThisMilestone always, while the plugin is read-only
     * @throws ConnectorException
     */
    public function deleteOverrides(WriteToken $token, string $listingRef, array $dates): void;

    /**
     * Changes listing-level settings (base, minimum, maximum).
     *
     * @param array<string, float> $fields
     *
     * @throws NotAvailableInThisMilestone always, while the plugin is read-only
     * @throws ConnectorException
     */
    public function updateListing(WriteToken $token, string $listingRef, array $fields): void;
}
