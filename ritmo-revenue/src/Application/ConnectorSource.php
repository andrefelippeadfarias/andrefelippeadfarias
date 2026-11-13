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
     * True unless the site switched off the pricing system's calendar read, the one read its API
     * serves by POST (ARCHITECTURE.md, section 6). When off, prices come from the PMS.
     */
    public function readsPricingCalendar(): bool;

    /**
     * The PMS of the listings as the pricing system names it: the payload of a plan carries it.
     */
    public function pricingPmsName(): string;
}
