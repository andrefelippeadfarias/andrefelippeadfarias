<?php

declare(strict_types=1);

namespace Ritmo\WordPress;

/**
 * Deactivation: stops the scheduled hook. Data, roles and settings stay until uninstall.
 */
final class Deactivator
{
    private function __construct()
    {
    }

    public static function deactivate(): void
    {
        Scheduler::unschedule();
    }
}
