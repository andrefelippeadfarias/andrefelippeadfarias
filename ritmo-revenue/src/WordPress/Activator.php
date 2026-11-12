<?php

declare(strict_types=1);

namespace Ritmo\WordPress;

use Ritmo\Domain\Config\RuleSet;

/**
 * Activation: creates or updates the tables, prepares the secrets vault, installs the role and
 * the capabilities, stores the neutral rules when there are none yet and schedules the hook that
 * resumes interrupted runs. Safe to run again.
 */
final class Activator
{
    private function __construct()
    {
    }

    public static function activate(): void
    {
        $services = Services::instance();
        $services->migrator()->migrate();
        $services->vault()->install();
        Roles::install();
        if ($services->ruleSets()->currentVersion() === null) {
            $services->ruleSets()->save(RuleSet::defaults(), 'Neutral defaults, stored at activation');
        }
        Scheduler::schedule();
    }
}
