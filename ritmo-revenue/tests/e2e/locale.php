<?php

/**
 * Sets the admin language of the test administrator on the throwaway site (the plugin's own
 * translation files only; WordPress' core is not translated there, nothing is downloaded):
 *
 *   php tests/e2e/locale.php <wordpress folder> <locale, e.g. pt_BR or en_US>
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli' || $argc !== 3 || !is_file($argv[1] . '/wp-load.php') || preg_match('/^[a-z]{2}_[A-Z]{2}$/', $argv[2]) !== 1) {
    fwrite(STDERR, "Usage: php tests/e2e/locale.php <wordpress folder> <locale>\n");
    exit(2);
}
require $argv[1] . '/wp-load.php';

$users = get_users(['role' => 'administrator', 'fields' => 'ID']);
foreach ($users as $id) {
    update_user_meta((int) $id, 'locale', $argv[2] === 'en_US' ? '' : $argv[2]);
}
echo sprintf("Admin language set to %s for %d administrator(s).\n", $argv[2], count($users));
