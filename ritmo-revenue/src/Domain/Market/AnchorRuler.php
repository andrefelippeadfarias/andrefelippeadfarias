<?php

declare(strict_types=1);

namespace Ritmo\Domain\Market;

/**
 * The anchor series of the market ruler: the property's market anchors as the series named
 * "market", with the anchor's percentiles (25, 50, 75, 90 and 100 for the highest price seen) as
 * public prices. Application\Market\MarketRulerAssembler puts it next to the series of the price
 * files; a night without an anchor nor a series has no ruler night, so no strategy acts there.
 */
final class AnchorRuler
{
    public const SERIES = 'market';

    private function __construct()
    {
    }
}
