<?php
/**
 * Installs the throwaway WordPress of bin/wp-test-site.sh and activates the plugins:
 *
 *   php tests/e2e/install.php <wordpress folder> <admin user> <admin password>
 *
 * Test only: the administrator exists only on that site. Every outgoing request is refused.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli' || $argc !== 4 || !is_file($argv[1] . '/wp-load.php')) {
    fwrite(STDERR, "Usage: php tests/e2e/install.php <wordpress folder> <admin user> <admin password>\n");
    exit(2);
}

define('WP_INSTALLING', true);

/** The test site sends no welcome e-mail (WordPress lets a plugin replace this function). */
function wp_new_blog_notification(): void
{
}

require $argv[1] . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/upgrade.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';
add_filter('pre_http_request', static fn () => new WP_Error('blocked', 'No network while installing the test site.'), 10, 3);

$installed = wp_install('Ritmo Revenue test site', $argv[2], 'e2e@example.test', false, '', $argv[3]);
if (!isset($installed['user_id'])) {
    fwrite(STDERR, "WordPress was not installed.\n");
    exit(1);
}

foreach (['sqlite-database-integration/load.php', 'ritmo-revenue/ritmo-revenue.php'] as $plugin) {
    $result = activate_plugin($plugin);
    if (is_wp_error($result)) {
        fwrite(STDERR, sprintf("Could not activate %s: %s\n", $plugin, $result->get_error_message()));
        exit(1);
    }
}

$user = get_user_by('login', $argv[2]);
if (!$user instanceof WP_User || !$user->has_cap('ritmo_manage_connections') || get_role('ritmo_revenue_manager') === null) {
    fwrite(STDERR, "The plugin was activated without its role or capabilities.\n");
    exit(1);
}
echo "WordPress installed and the plugin activated.\n";
