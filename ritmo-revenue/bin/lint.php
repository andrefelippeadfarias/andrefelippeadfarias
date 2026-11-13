<?php

/**
 * The plugin's lint, without WPCS (it cannot be downloaded here): php -l on every PHP file,
 * escaped output and no request reads in the templates, a real permission_callback on every REST
 * route, the capability and the nonce of every form action, nothing loaded from another site.
 * See bin/lint/Lint.php for the rules.
 *
 *   php bin/lint.php    exits 1 when a rule finds a problem
 */

declare(strict_types=1);

require __DIR__ . '/lint/Lint.php';

$failed = false;
foreach ((new Ritmo\Tools\Lint\Lint(dirname(__DIR__)))->run() as $rule => $problems) {
    if ($problems === []) {
        echo "ok   $rule\n";
        continue;
    }
    $failed = true;
    echo "FAIL $rule\n";
    foreach ($problems as $problem) {
        echo "     $problem\n";
    }
}
exit($failed ? 1 : 0);
