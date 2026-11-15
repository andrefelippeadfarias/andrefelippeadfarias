<?php

/**
 * The plugin promises PHP 8.1+, and is developed on a newer PHP whose php -l accepts syntax that
 * 8.1 refuses. This finds that syntax and the functions and classes added after 8.1 (readonly
 * classes, DNF types, true/null/false as types, constants in traits, typed class constants,
 * dynamic class constant fetch, static variables initialised with an expression, json_validate()
 * and the like). See bin/package/Php81Lint.php for the list.
 *
 *   php bin/lint-php81.php              every PHP file the plugin ships (main file, uninstall.php,
 *                                       src/, templates/, languages/)
 *   php bin/lint-php81.php <path>...    these files and folders (bin/build.sh: the staged package)
 *
 * Exits 1 when a file uses something PHP 8.1 does not have.
 */

declare(strict_types=1);

require __DIR__ . '/package/Php81Lint.php';

use Ritmo\Tools\Package\Php81Lint;

$root = dirname(__DIR__);
$paths = array_slice($argv, 1);
if ($paths === []) {
    foreach (['ritmo-revenue.php', 'uninstall.php', 'src', 'templates', 'languages'] as $path) {
        if (file_exists($root . '/' . $path)) {
            $paths[] = $root . '/' . $path;
        }
    }
}

try {
    $files = Php81Lint::phpFiles($paths);
    $found = Php81Lint::checkPaths($paths);
} catch (RuntimeException $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(2);
}

if ($found === []) {
    echo 'ok   PHP 8.1 syntax (' . count($files) . " files)\n";
    exit(0);
}
echo "FAIL PHP 8.1 syntax\n";
foreach ($found as $file => $problems) {
    $shown = str_starts_with($file, $root . '/') ? substr($file, strlen($root) + 1) : $file;
    foreach ($problems as $problem) {
        echo "     $shown:$problem\n";
    }
}
exit(1);
