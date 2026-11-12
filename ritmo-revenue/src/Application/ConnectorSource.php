<?php

declare(strict_types=1);

namespace Ritmo\Application;

use Ritmo\Contracts\PmsConnector;
use Ritmo\Contracts\PricingConnector;

/**
 * Where the use cases get their connectors. The connectors are built on demand because they need
 * credentials that may not be configured yet.
 */
interface ConnectorSource
{
    /**
     * @throws ConnectionNotConfigured when the PMS credentials are missing
     */
    public function pms(): PmsConnector;

    /**
     * @throws ConnectionNotConfigured when the pricing system credentials are missing
     */
    public function pricing(): PricingConnector;

    /**
     * True only when the site explicitly allowed the pricing system's calendar read, the one read
     * its API serves by POST (ARCHITECTURE.md, section 6). Otherwise prices come from the PMS.
     */
    public function readsPricingCalendar(): bool;
}
