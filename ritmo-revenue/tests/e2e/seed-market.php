<?php
/**
 * Adds competitor prices for the next sixty nights to the throwaway site (the same prices as
 * tests/e2e/seed.php), for the wizard pass of tests/e2e/m4-screens.js: a site set up through the
 * wizard has no market prices, and without them the shadow run decides nothing. Test only.
 *
 *   php tests/e2e/seed-market.php <wordpress folder> <property id>
 */

declare(strict_types=1);

use Ritmo\Domain\Support\Days;
use Ritmo\WordPress\Services;

if (PHP_SAPI !== 'cli' || $argc !== 3 || !is_file($argv[1] . '/wp-load.php')) {
    fwrite(STDERR, "Usage: php tests/e2e/seed-market.php <wordpress folder> <property id>\n");
    exit(2);
}

require $argv[1] . '/wp-load.php';

$property = Services::instance()->properties()->find($argv[2]);
if ($property === null) {
    fwrite(STDERR, "Unknown property.\n");
    exit(1);
}
$today = Services::instance()->clock()->today($property->timezone);
$lines = ['date,price'];
for ($day = 0; $day < 60; ++$day) {
    $night = Days::add($today, $day);
    $weekend = in_array(Days::weekday($night), [4, 5], true);
    foreach ([380, 420, 470, 520, 610, 720] as $price) {
        $lines[] = Days::key($night) . ',' . ($weekend ? round($price * 1.3) : $price);
    }
}
$samples = Services::instance()->setup()->importSamples($property->id, implode("\n", $lines));

printf("%d market prices for %d nights.\n", $samples['samples'], $samples['nights']);
