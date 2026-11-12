<?php
/**
 * Reads a rule set (JSON) on stdin and prints it with every missing field that has a default
 * filled in (Schema::withDefaults), so that a generated preset passes the strict validator.
 * Used by make_parity_preset.py for the sections the module fixtures write in short form.
 *
 * Usage: php tests/fixtures/tools/fill_defaults.php < rules.json
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require dirname(__DIR__, 3) . '/src/autoload.php';

use Ritmo\Domain\Config\Schema;

$rules = json_decode((string) stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
if (!is_array($rules)) {
    fwrite(STDERR, "The input is not a JSON object.\n");
    exit(1);
}
echo json_encode(Schema::ruleSet()->withDefaults($rules), JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
