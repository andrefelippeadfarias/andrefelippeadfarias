<?php

declare(strict_types=1);

namespace Ritmo\WordPress;

use Ritmo\Admin\Licence\LicenceHooks;

/**
 * Deactivation: stops the scheduled hooks (the scheduler tick and the licence check). Data, roles
 * and settings stay until uninstall.
 */
final class Deactivator
{
    private function __construct()
    {
    }

    public static function deactivate(): void
    {
        Scheduler::unschedule();
        LicenceHooks::unschedule();
    }
}
