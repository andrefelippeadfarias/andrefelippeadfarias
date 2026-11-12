<?php

declare(strict_types=1);

namespace Ritmo;

/**
 * Composition root of the plugin.
 *
 * `boot()` is hooked to `plugins_loaded` by the main plugin file. Modules register their own
 * hooks from here as they are added (infrastructure, admin, REST, scheduler). It is safe to
 * call more than once.
 */
final class Plugin
{
    private static bool $booted = false;

    public static function boot(): void
    {
        if (self::$booted) {
            return;
        }

        self::$booted = true;
    }

    public static function isBooted(): bool
    {
        return self::$booted;
    }
}
