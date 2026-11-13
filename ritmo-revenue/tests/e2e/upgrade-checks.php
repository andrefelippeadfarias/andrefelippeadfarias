<?php

/**
 * The update from the M3 plugin (run by bin/e2e.sh after the site was built and seeded with the M3
 * code and the plugin folder was switched to this code): the first request brings the tables up
 * to the latest migration and the roles to the current grants, the stored rules gain the M4
 * sections at their neutral defaults, and every screen of the menu renders for the administrator
 * without a PHP error.
 *
 *   php tests/e2e/upgrade-checks.php <wordpress folder>
 */

declare(strict_types=1);

use Ritmo\Admin\AdminScreens;
use Ritmo\Admin\Components\Menu;
use Ritmo\WordPress\Roles;
use Ritmo\WordPress\Services;

if (PHP_SAPI !== 'cli' || !isset($argv[1]) || !is_file($argv[1] . '/wp-load.php')) {
    fwrite(STDERR, "Usage: php tests/e2e/upgrade-checks.php <wordpress folder>\n");
    exit(2);
}
require $argv[1] . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/admin.php';

function check(bool $ok, string $what): void
{
    if (!$ok) {
        fwrite(STDERR, "UPGRADE FAILED: $what\n");
        exit(1);
    }
}

$plugin = dirname(__DIR__, 2);
set_error_handler(static function (int $severity, string $message, string $file, int $line) use ($plugin): bool {
    if (str_starts_with($file, $plugin . '/src/') || str_starts_with($file, $plugin . '/templates/')) {
        fwrite(STDERR, "UPGRADE FAILED: PHP error in the plugin: $message ($file:$line)\n");
        exit(1);
    }

    return false;
});

// Loading WordPress ran "init": the plugin brought the tables, the roles and the rules up to date.
$services = Services::instance();
$migrator = $services->migrator();
check($migrator->currentVersion() === $migrator->latestVersion(), sprintf('the database is at version %d of %d', $migrator->currentVersion(), $migrator->latestVersion()));
check($migrator->latestVersion() >= 12, 'the M4 migrations are not there');

$admin = get_role('administrator');
check($admin !== null && $admin->has_cap(Roles::MANAGE_SAFETY), 'the administrator did not get ritmo_manage_safety');
check(get_role(Roles::VIEWER_ROLE) !== null && get_role(Roles::VIEWER_ROLE)->has_cap(Roles::VIEW), 'the viewer role was not created');
check(get_role(Roles::ROLE) !== null && !get_role(Roles::ROLE)->has_cap(Roles::MANAGE_SAFETY), 'the manager role got ritmo_manage_safety');

$rules = $services->ruleSets()->current();
check($rules !== null, 'the stored rules cannot be read after the update');
$data = $rules->toArray();
check(isset($data['market'], $data['reports'], $data['stay']['write']), 'the stored rules lack the M4 sections');
check($data['stay']['write']['enabled'] === false, 'the update switched the stay writes on');

$users = get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ID']);
wp_set_current_user((int) $users[0]);
$rendered = 0;
foreach (AdminScreens::renderers() as $slug => $render) {
    $_GET = ['page' => $slug];
    ob_start();
    $render();
    $html = (string) ob_get_clean();
    check(str_contains($html, '<h1'), "the screen $slug does not render");
    ++$rendered;
}
check($rendered === count(Menu::entries()), "$rendered screens rendered of " . count(Menu::entries()));
echo "Update from M3 OK: database {$migrator->currentVersion()}, roles and capabilities current, $rendered screens rendered.\n";
