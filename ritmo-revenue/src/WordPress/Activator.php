<?php

declare(strict_types=1);

namespace Ritmo\WordPress;

use Ritmo\Domain\Config\InvalidConfig;
use Ritmo\Domain\Config\RuleSet;

/**
 * Activation and update: schedules the scheduler tick (and clears the event of earlier
 * versions) first, then creates or updates the tables, prepares the secrets vault, installs the
 * role and the capabilities, and stores the neutral rules when there are none yet or completes
 * the stored ones with the settings an update added. Each step stands on its own: rules that do
 * not pass validation are logged and leave the rest done (the screens show the problem; no run
 * starts on them). Safe to run again.
 */
final class Activator
{
    private function __construct()
    {
    }

    public static function activate(): void
    {
        $services = Services::instance();
        Scheduler::schedule();
        $services->migrator()->migrate();
        $services->vault()->install();
        Roles::install($services->store());
        try {
            if ($services->ruleSets()->currentVersion() === null) {
                $services->ruleSets()->save(RuleSet::defaults(), 'Neutral defaults, stored at activation');
            } else {
                $services->ruleSets()->upgradeCurrent();
            }
        } catch (InvalidConfig $invalid) {
            $services->logger()->error('The stored rules do not pass validation after the update.', ['errors' => count($invalid->errors())]);
        }
    }
}
