<?php

declare(strict_types=1);

namespace Ritmo\Tests\Unit\Application;

use DateTimeImmutable;
use Ritmo\Contracts\ConnectionStatus;
use Ritmo\Contracts\PricingConnector;
use Ritmo\Contracts\Support\PricingWritesUnavailable;
use Throwable;

/**
 * A pricing system that fails every read with the given exception.
 */
final class FailingPricingConnector implements PricingConnector
{
    use PricingWritesUnavailable;

    public function __construct(private readonly Throwable $failure)
    {
    }

    public function id(): string
    {
        return 'failing-pricing';
    }

    public function testConnection(): ConnectionStatus
    {
        return new ConnectionStatus(false, $this->failure->getMessage());
    }

    public function listListings(): array
    {
        throw $this->failure;
    }

    public function readPrices(string $listingRef, DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        throw $this->failure;
    }

    public function readOverrides(string $listingRef, DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        throw $this->failure;
    }
}
