<?php
/**
 * PHPUnit bootstrap: the plugin autoloader plus a PSR-4 autoloader for Ritmo\Tests\ => tests/.
 *
 * WordPress is NOT loaded here. Tests that need it boot their own environment.
 */

declare(strict_types=1);

error_reporting(E_ALL);
date_default_timezone_set('UTC');

require_once dirname(__DIR__) . '/src/autoload.php';

spl_autoload_register(
    static function (string $class): void {
        $prefix = 'Ritmo\\Tests\\';
        if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
            return;
        }

        $relative = substr($class, strlen($prefix));
        $file = __DIR__ . '/' . str_replace('\\', '/', $relative) . '.php';
        if (is_file($file)) {
            require $file;
        }
    },
    true,
    true
);
