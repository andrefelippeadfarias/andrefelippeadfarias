<?php

/**
 * Compiles languages/ritmo-revenue-pt_BR.po into ritmo-revenue-pt_BR.mo and
 * ritmo-revenue-pt_BR.l10n.php (the format WordPress 6.5+ loads first), without msgfmt or wp-cli.
 *
 *   php bin/compile-l10n.php            writes both files for every languages/ritmo-revenue-*.po
 *   php bin/compile-l10n.php --check    exits 1 when a compiled file does not match its .po
 */

declare(strict_types=1);

require __DIR__ . '/i18n/Po.php';
require __DIR__ . '/i18n/Compiler.php';

use Ritmo\Tools\I18n\Compiler;
use Ritmo\Tools\I18n\Po;

$root = dirname(__DIR__);
$check = in_array('--check', $argv, true);
$stale = false;
foreach (glob($root . '/languages/ritmo-revenue-*.po') ?: [] as $po) {
    $compiler = new Compiler(Po::parse((string) file_get_contents($po)));
    $base = substr($po, 0, -3);
    foreach ([$base . '.mo' => $compiler->mo(), $base . '.l10n.php' => $compiler->php()] as $file => $content) {
        if ($check) {
            if (!is_file($file) || file_get_contents($file) !== $content) {
                fwrite(STDERR, basename($file) . " is stale: run php bin/compile-l10n.php\n");
                $stale = true;
            }
            continue;
        }
        file_put_contents($file, $content);
    }
    echo basename($po) . ': ' . $compiler->count() . " translations" . ($check ? ' checked' : ' compiled') . ".\n";
}
exit($stale ? 1 : 0);
