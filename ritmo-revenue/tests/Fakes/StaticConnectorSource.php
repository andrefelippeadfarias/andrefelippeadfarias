<?php

declare(strict_types=1);

namespace Ritmo\Tests\Fakes;

use Ritmo\Application\ConnectionNotConfigured;
use Ritmo\Application\ConnectorSource;
use Ritmo\Contracts\PmsConnector;
use Ritmo\Contracts\PricingConnector;

/**
 * A ConnectorSource that hands out the connectors it was given; a missing one is "not
 * configured", as on a site without credentials.
 */
final class StaticConnectorSource implements ConnectorSource
{
    public function __construct(
        private readonly ?PmsConnector $pms,
        private readonly ?PricingConnector $pricing,
        private readonly bool $readsPricingCalendar = false,
        private readonly string $pricingPms = 'beds24',
    ) {
    }

    public function pricingPmsName(): string
    {
        return $this->pricingPms;
    }

    public function pms(): PmsConnector
    {
        return $this->pms ?? throw ConnectionNotConfigured::forProvider('PMS');
    }

    public function pricing(): PricingConnector
    {
        return $this->pricing ?? throw ConnectionNotConfigured::forProvider('pricing system');
    }

    public function readsPricingCalendar(): bool
    {
        return $this->readsPricingCalendar;
    }
}
