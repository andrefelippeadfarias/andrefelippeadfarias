<?php

/**
 * Extracts the translatable texts of the plugin into languages/ritmo-revenue.pot, without
 * wp-cli and without the network: reads src/, templates/ and the main file with token_get_all()
 * and keeps every call of the WordPress translation functions whose text domain is
 * "ritmo-revenue" and whose texts are literal strings, with the "translators:" comment written
 * just before the call and the file and line of each use.
 *
 *   php bin/make-pot.php            writes languages/ritmo-revenue.pot
 *   php bin/make-pot.php --check    exits 1 when the file on disk is not what the code gives
 *
 * The output has no date, so the same code always gives the same file.
 */

declare(strict_types=1);

require __DIR__ . '/i18n/Po.php';
require __DIR__ . '/i18n/PotExtractor.php';

$root = dirname(__DIR__);
$extractor = new Ritmo\Tools\I18n\PotExtractor($root, 'ritmo-revenue');
$pot = $extractor->pot();
$target = $root . '/languages/ritmo-revenue.pot';

foreach ($extractor->warnings() as $warning) {
    fwrite(STDERR, $warning . "\n");
}

if (in_array('--check', $argv, true)) {
    if (!is_file($target) || file_get_contents($target) !== $pot) {
        fwrite(STDERR, "languages/ritmo-revenue.pot is stale: run php bin/make-pot.php\n");
        exit(1);
    }
    echo "languages/ritmo-revenue.pot is up to date (" . $extractor->count() . " texts).\n";
    exit(0);
}

if (!is_dir(dirname($target))) {
    mkdir(dirname($target), 0755, true);
}
file_put_contents($target, $pot);
echo "languages/ritmo-revenue.pot written (" . $extractor->count() . " texts).\n";
