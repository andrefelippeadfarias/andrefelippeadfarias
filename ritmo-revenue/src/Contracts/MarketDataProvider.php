<?php

declare(strict_types=1);

namespace Ritmo\Contracts;

use DateTimeImmutable;
use Ritmo\Contracts\Exception\ConnectorException;
use Ritmo\Domain\Model\MarketAnchor;

/**
 * A source of market anchors (competitor price percentiles per night): samples typed or
 * imported by the user, or market data of the pricing system. No scraping.
 */
interface MarketDataProvider
{
    /**
     * Stable id of the provider ("manual_csv").
     */
    public function id(): string;

    /**
     * The anchors the provider has for a property on the nights [$from, $to] (both inclusive).
     *
     * @return array<string, MarketAnchor> "YYYY-MM-DD" => anchor, ascending; nights without data are absent
     *
     * @throws ConnectorException
     */
    public function readAnchors(string $propertyId, DateTimeImmutable $from, DateTimeImmutable $to): array;
}
