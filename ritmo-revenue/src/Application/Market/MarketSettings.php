<?php

declare(strict_types=1);

namespace Ritmo\Application\Market;

use Ritmo\Domain\Config\InvalidConfig;
use Ritmo\Domain\Config\RuleSet;
use Ritmo\Domain\Market\Series\MarketConfig;
use Ritmo\Domain\Market\Series\MarketFields;

/**
 * The market section of the rules in force: its neutral defaults until the section exists in the
 * rule set (the integration adds MarketFields to the schema).
 */
final class MarketSettings
{
    private function __construct()
    {
    }

    /**
     * @throws InvalidConfig when the section is there but does not read
     */
    public static function of(?RuleSet $rules): MarketConfig
    {
        $section = $rules?->toArray()[MarketFields::ROOT] ?? [];

        return MarketConfig::fromArray(is_array($section) ? $section : []);
    }
}
