<?php
/**
 * Rewrites the two files that are generated from the code:
 *
 *   presets/default.json   the neutral preset: exactly the defaults of the rule set schema
 *   docs/CONTRACTS.md      the signature reference at the end of the document
 *
 * Usage: php bin/sync-generated.php
 *
 * Run it after adding or changing a rule field (src/Domain/Config/RuleSetFields.php) or the
 * public surface of anything under src/Contracts, src/Domain/Model, src/Domain/Support or
 * src/Domain/Config. Tests fail while either file is stale.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require dirname(__DIR__) . '/tests/bootstrap.php';

use Ritmo\Domain\Config\Preset;
use Ritmo\Domain\Config\RuleSet;
use Ritmo\Tests\Support\ContractsDoc;

$preset = new Preset(
    'Neutral defaults',
    'Generic starting point: no property, no holiday, no owner floor and price factors of 1. The setup wizard adds the properties and the owner adjusts the rules.',
    [],
    [],
    RuleSet::defaults()
);
$json = json_encode($preset->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
$path = dirname(__DIR__) . '/presets/default.json';
$presetChanged = !is_file($path) || file_get_contents($path) !== $json;
if ($presetChanged) {
    file_put_contents($path, $json);
}

fwrite(STDOUT, 'presets/default.json ' . ($presetChanged ? "updated.\n" : "is up to date.\n"));
fwrite(STDOUT, 'docs/CONTRACTS.md ' . (ContractsDoc::sync() ? "updated.\n" : "is up to date.\n"));
