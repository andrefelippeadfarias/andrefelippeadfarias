<?php

/**
 * Moves the stored licence record of the throwaway site back in time, as if its answers had come
 * N days ago (the end-to-end test cannot wait out a 30-day grace period):
 *
 *   php tests/e2e/licence-age.php <wordpress folder> <days>
 *
 * Only the instants of the record move; the key in the vault is not touched.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli' || $argc !== 3 || !is_file($argv[1] . '/wp-load.php') || preg_match('/^\d{1,3}$/', $argv[2]) !== 1) {
    fwrite(STDERR, "Usage: php tests/e2e/licence-age.php <wordpress folder> <days>\n");
    exit(2);
}
require $argv[1] . '/wp-load.php';

$option = 'ritmo_licence_state';
// The plugin's settings are JSON text (Infrastructure\Store\OptionsStore).
$stored = get_option($option);
$record = is_string($stored) ? json_decode($stored, true) : null;
if (!is_array($record)) {
    fwrite(STDERR, "No licence record is stored.\n");
    exit(1);
}
$seconds = (int) $argv[2] * 86400;
foreach (['expires_at', 'last_answer_at', 'last_attempt_at', 'last_valid_at', 'problem_since'] as $field) {
    if (is_string($record[$field] ?? null)) {
        $record[$field] = gmdate('Y-m-d\TH:i:s\Z', (int) strtotime($record[$field]) - $seconds);
    }
}
update_option($option, (string) json_encode($record));
echo sprintf("Licence record moved back %d day(s).\n", (int) $argv[2]);
