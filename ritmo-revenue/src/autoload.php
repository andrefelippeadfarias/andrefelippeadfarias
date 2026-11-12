<?php
/**
 * PSR-4 autoloader for the Ritmo\ namespace (Ritmo\ => src/).
 *
 * The plugin ships without Composer runtime dependencies, so this is the only autoloader.
 *
 * @package Ritmo
 */

declare(strict_types=1);

spl_autoload_register(
    static function (string $class): void {
        $prefix = 'Ritmo\\';
        if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
            return;
        }

        $relative = substr($class, strlen($prefix));
        $file = __DIR__ . '/' . str_replace('\\', '/', $relative) . '.php';
        if (is_file($file)) {
            require $file;
        }
    }
);
