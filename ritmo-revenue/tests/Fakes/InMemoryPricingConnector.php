<?php

declare(strict_types=1);

namespace Ritmo\Tests\Fakes;

use DateTimeImmutable;
use Ritmo\Contracts\ConnectionStatus;
use Ritmo\Contracts\Exception\ConnectorException;
use Ritmo\Contracts\PricingConnector;
use Ritmo\Contracts\Support\PricingWritesUnavailable;
use Ritmo\Domain\Model\DateOverride;
use Ritmo\Domain\Model\ListingInfo;
use Ritmo\Domain\Model\PriceNight;
use Ritmo\Domain\Support\DateRange;
use Ritmo\Domain\Support\Days;

/**
 * A PricingConnector over data held in memory, with the semantics of the contract (and of the
 * PriceLabs connector): nights ascending inside the inclusive range, a listing it does not know
 * is a ConnectorException. It records which reads were made.
 */
final class InMemoryPricingConnector implements PricingConnector
{
    use PricingWritesUnavailable;

    /** @var list<string> */
    private array $calls = [];

    /**
     * @param list<ListingInfo>                 $listings
     * @param array<string, list<PriceNight>>   $prices    listing ref => nights (any order)
     * @param array<string, list<DateOverride>> $overrides listing ref => overrides (any order)
     */
    public function __construct(
        private readonly array $listings = [],
        private readonly array $prices = [],
        private readonly array $overrides = [],
        private readonly string $id = 'memory-pricing',
    ) {
    }

    public function id(): string
    {
        return $this->id;
    }

    public function testConnection(): ConnectionStatus
    {
        $this->calls[] = 'testConnection';

        return new ConnectionStatus(true, 'In-memory pricing system.', ['listings' => count($this->listings)]);
    }

    public function listListings(): array
    {
        $this->calls[] = 'listListings';

        return $this->listings;
    }

    public function readPrices(string $listingRef, DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $this->calls[] = 'readPrices';
        $this->assertKnown($listingRef);

        return self::inRange($this->prices[$listingRef] ?? [], new DateRange($from, $to));
    }

    public function readOverrides(string $listingRef, DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $this->calls[] = 'readOverrides';
        $this->assertKnown($listingRef);

        return self::inRange($this->overrides[$listingRef] ?? [], new DateRange($from, $to));
    }

    /**
     * Names of the read methods called, in order.
     *
     * @return list<string>
     */
    public function calls(): array
    {
        return $this->calls;
    }

    /**
     * @throws ConnectorException for a listing that is neither listed nor has data, as the real system answers
     */
    private function assertKnown(string $listingRef): void
    {
        $listed = array_filter($this->listings, static fn (ListingInfo $l): bool => $l->ref === $listingRef) !== [];
        if (!$listed && !isset($this->prices[$listingRef]) && !isset($this->overrides[$listingRef])) {
            throw new ConnectorException('The in-memory pricing system has no such listing.', $this->id);
        }
    }

    /**
     * @template T of PriceNight|DateOverride
     *
     * @param list<T> $nights
     *
     * @return list<T>
     */
    private static function inRange(array $nights, DateRange $range): array
    {
        $kept = [];
        foreach ($nights as $night) {
            if ($range->contains($night->date)) {
                $kept[Days::key($night->date)] = $night;
            }
        }
        ksort($kept);

        return array_values($kept);
    }
}
