<?php
/**
 * Prepares the throwaway site for tests/e2e/m5-ai.js: an open alert of the invented property for
 * the "explain" button (market prices nine days old, a fact of the plugin's own; no guest data),
 * and prints its id. Test only.
 *
 *   php tests/e2e/seed-ai.php <wordpress folder> <property id>
 */

declare(strict_types=1);

use Ritmo\Domain\Alerts\AlertCode;
use Ritmo\Infrastructure\Db\Repository\AlertRepository;
use Ritmo\WordPress\Services;

if (PHP_SAPI !== 'cli' || $argc !== 3 || !is_file($argv[1] . '/wp-load.php')) {
    fwrite(STDERR, "Usage: php tests/e2e/seed-ai.php <wordpress folder> <property id>\n");
    exit(2);
}

require $argv[1] . '/wp-load.php';

$property = Services::instance()->properties()->find($argv[2]);
if ($property === null) {
    fwrite(STDERR, "Unknown property {$argv[2]}\n");
    exit(1);
}

$id = Services::instance()->alerts()->raise(
    AlertCode::MarketStale->value,
    AlertRepository::WARNING,
    'The market prices of the property are 9 days old.',
    ['days' => 9],
    $property->id,
    '',
    null,
    'e2e-ai:market_stale:' . $property->id,
);

echo $id, "\n";
