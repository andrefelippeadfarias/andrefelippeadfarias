<?php

declare(strict_types=1);

namespace Ritmo\Application\Report;

use Ritmo\Domain\Config\InvalidConfig;
use Ritmo\Domain\Config\RuleSet;
use Ritmo\Domain\Report\Config\ReportsConfig;
use Ritmo\Domain\Report\Config\ReportsFields;

/**
 * The "reports" section of the rule set in force (neutral until the integration adds the section).
 */
final class ReportSettings
{
    private function __construct()
    {
    }

    /**
     * @throws InvalidConfig when the section is there but does not read
     */
    public static function of(?RuleSet $rules): ReportsConfig
    {
        $section = $rules?->toArray()[ReportsFields::ROOT] ?? [];

        return ReportsConfig::fromArray(is_array($section) ? $section : []);
    }
}
