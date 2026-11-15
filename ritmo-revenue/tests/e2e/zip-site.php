<?php
/**
 * The WordPress side of bin/e2e-zip.sh, on the throwaway site of bin/wp-test-site.sh:
 *
 *   php tests/e2e/zip-site.php install <wordpress folder> <admin user> <admin password>
 *       a clean WordPress (only the SQLite plugin active, the plugin not installed) with things that
 *       are not the plugin's and must survive its uninstall: an option, a table with a row, an
 *       editor with a user meta, a role with a capability, a cron event
 *   php tests/e2e/zip-site.php snapshot <wordpress folder> <file>
 *       what the site holds (tables, options, roles and capabilities, cron hooks, users and their meta keys)
 *   php tests/e2e/zip-site.php present <wordpress folder>
 *       the installed plugin made its tables, settings, roles, capabilities and cron events
 *   php tests/e2e/zip-site.php residue <wordpress folder> <snapshot file>
 *       after deleting the plugin: nothing of it is left and everything of the snapshot still is
 *
 * Prints one JSON line; exits 1 when a check fails. Test only.
 */

declare(strict_types=1);

const RITMO_E2E_SENTINEL_OPTION = 'e2e_keep_option';
const RITMO_E2E_SENTINEL_TABLE = 'e2e_keep';
const RITMO_E2E_SENTINEL_USER = 'e2e-keep-editor';
const RITMO_E2E_SENTINEL_META = 'e2e_keep_meta';
const RITMO_E2E_SENTINEL_ROLE = 'e2e_keep_role';
const RITMO_E2E_SENTINEL_HOOK = 'e2e_keep_event';
const RITMO_E2E_PLUGIN_FILE = 'ritmo-revenue/ritmo-revenue.php';

$command = $argv[1] ?? '';
$wordpress = $argv[2] ?? '';
$usage = "Usage: php tests/e2e/zip-site.php install|snapshot|present|residue <wordpress folder> [...]\n";
if (PHP_SAPI !== 'cli' || !in_array($command, ['install', 'snapshot', 'present', 'residue'], true) || !is_file($wordpress . '/wp-load.php')) {
    fwrite(STDERR, $usage);
    exit(2);
}

if ($command === 'install') {
    define('WP_INSTALLING', true);

    /** The test site sends no welcome e-mail (WordPress lets a plugin replace this function). */
    function wp_new_blog_notification(): void
    {
    }
}

require $wordpress . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';
add_filter('pre_http_request', static fn () => new WP_Error('blocked', 'No network on the test site.'), 10, 3);

/**
 * @param array<string, mixed> $result
 */
function ritmo_e2e_finish(array $result, array $problems): never
{
    echo json_encode(['ok' => $problems === [], 'problems' => $problems] + $result, JSON_UNESCAPED_SLASHES) . "\n";
    exit($problems === [] ? 0 : 1);
}

/**
 * @return array<string, mixed>
 */
function ritmo_e2e_snapshot(): array
{
    global $wpdb;
    $tables = $wpdb->get_col('SHOW TABLES');
    sort($tables);
    $options = $wpdb->get_col("SELECT option_name FROM {$wpdb->options} WHERE option_name NOT LIKE '\\_transient\\_%' AND option_name NOT LIKE '\\_site\\_transient\\_%' ORDER BY option_name");
    $roles = [];
    foreach (wp_roles()->roles as $name => $role) {
        $caps = array_keys(array_filter($role['capabilities']));
        sort($caps);
        $roles[$name] = $caps;
    }
    ksort($roles);
    $hooks = [];
    foreach (_get_cron_array() ?: [] as $events) {
        foreach (array_keys($events) as $hook) {
            $hooks[$hook] = true;
        }
    }
    $hooks = array_keys($hooks);
    sort($hooks);
    $users = [];
    foreach (get_users(['fields' => ['ID', 'user_login']]) as $user) {
        $keys = $wpdb->get_col($wpdb->prepare("SELECT DISTINCT meta_key FROM {$wpdb->usermeta} WHERE user_id = %d ORDER BY meta_key", $user->ID));
        $users[$user->user_login] = ['caps' => array_keys(array_filter((array) get_userdata((int) $user->ID)->caps)), 'meta' => $keys];
    }
    ksort($users);

    return [
        'tables' => $tables,
        'options' => $options,
        'roles' => $roles,
        'cron' => $hooks,
        'users' => $users,
        'active_plugins' => (array) get_option('active_plugins', []),
    ];
}

/**
 * Everything of the plugin the site holds: a "ritmo" table, option, transient, user meta key, role,
 * capability (of a role or of a user), cron hook, active plugin entry or file.
 *
 * @return array<string, list<string>>
 */
function ritmo_e2e_plugin_traces(): array
{
    global $wpdb;
    $snapshot = ritmo_e2e_snapshot();
    $traces = [
        'tables' => array_values(array_filter($snapshot['tables'], static fn (string $t): bool => stripos($t, 'ritmo') !== false)),
        'options' => $wpdb->get_col("SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE '%ritmo%'"),
        'user meta' => $wpdb->get_col("SELECT DISTINCT meta_key FROM {$wpdb->usermeta} WHERE meta_key LIKE '%ritmo%'"),
        'roles' => array_values(array_filter(array_keys($snapshot['roles']), static fn (string $r): bool => str_starts_with($r, 'ritmo'))),
        'capabilities of roles' => [],
        'capabilities of users' => [],
        'cron' => array_values(array_filter($snapshot['cron'], static fn (string $h): bool => str_starts_with($h, 'ritmo'))),
        'active plugins' => array_values(array_filter($snapshot['active_plugins'], static fn (string $p): bool => str_starts_with($p, 'ritmo-revenue/'))),
        'files' => [],
    ];
    foreach ($snapshot['roles'] as $role => $caps) {
        foreach ($caps as $cap) {
            if (str_starts_with($cap, 'ritmo')) {
                $traces['capabilities of roles'][] = "$role: $cap";
            }
        }
    }
    foreach ($snapshot['users'] as $login => $user) {
        foreach ($user['caps'] as $cap) {
            if (str_starts_with((string) $cap, 'ritmo')) {
                $traces['capabilities of users'][] = "$login: $cap";
            }
        }
    }
    foreach ([WP_PLUGIN_DIR . '/ritmo-revenue', WP_CONTENT_DIR . '/uploads/ritmo-revenue', WP_CONTENT_DIR . '/languages/plugins/ritmo-revenue-pt_BR.mo'] as $path) {
        if (file_exists($path) || is_link($path)) {
            $traces['files'][] = substr($path, strlen(ABSPATH));
        }
    }

    return $traces;
}

$problems = [];
switch ($command) {
    case 'install':
        [$user, $password] = [$argv[3] ?? '', $argv[4] ?? ''];
        if ($user === '' || $password === '') {
            fwrite(STDERR, $usage);
            exit(2);
        }
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $installed = wp_install('Ritmo Revenue clean test site', $user, 'e2e@example.test', false, '', $password);
        if (!isset($installed['user_id'])) {
            ritmo_e2e_finish([], ['WordPress was not installed.']);
        }
        $activated = activate_plugin('sqlite-database-integration/load.php');
        if (is_wp_error($activated)) {
            ritmo_e2e_finish([], ['The SQLite plugin was not activated: ' . $activated->get_error_message()]);
        }
        if (is_dir(WP_PLUGIN_DIR . '/ritmo-revenue') || is_link(WP_PLUGIN_DIR . '/ritmo-revenue')) {
            ritmo_e2e_finish([], ['The clean site already has the plugin folder.']);
        }
        global $wpdb;
        update_option(RITMO_E2E_SENTINEL_OPTION, 'kept');
        $table = $wpdb->prefix . RITMO_E2E_SENTINEL_TABLE;
        $wpdb->query("CREATE TABLE $table (id INTEGER PRIMARY KEY, label VARCHAR(20) NOT NULL)");
        $wpdb->insert($table, ['id' => 1, 'label' => 'kept']);
        $editor = wp_insert_user(['user_login' => RITMO_E2E_SENTINEL_USER, 'user_pass' => wp_generate_password(24), 'user_email' => 'editor@example.test', 'role' => 'editor']);
        if (is_wp_error($editor)) {
            ritmo_e2e_finish([], ['The editor was not created: ' . $editor->get_error_message()]);
        }
        update_user_meta($editor, RITMO_E2E_SENTINEL_META, 'kept');
        add_role(RITMO_E2E_SENTINEL_ROLE, 'Kept role', ['read' => true, 'e2e_keep_cap' => true]);
        wp_schedule_event(time() + DAY_IN_SECONDS, 'daily', RITMO_E2E_SENTINEL_HOOK);
        $traces = array_filter(ritmo_e2e_plugin_traces());
        ritmo_e2e_finish(['installed' => true], $traces === [] ? [] : ['The clean site already has traces of the plugin: ' . json_encode($traces)]);

    case 'snapshot':
        $file = $argv[3] ?? '';
        if ($file === '') {
            fwrite(STDERR, $usage);
            exit(2);
        }
        file_put_contents($file, json_encode(ritmo_e2e_snapshot(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        ritmo_e2e_finish(['snapshot' => $file], []);

    case 'present':
        $traces = ritmo_e2e_plugin_traces();
        $admin = get_users(['role' => 'administrator', 'number' => 1])[0] ?? null;
        $expect = [
            'tables' => count($traces['tables']) >= 5,
            'options' => $traces['options'] !== [],
            'roles' => in_array('ritmo_revenue_manager', $traces['roles'], true) && in_array('ritmo_revenue_viewer', $traces['roles'], true),
            'capabilities of the administrator' => $admin instanceof WP_User && $admin->has_cap('ritmo_manage_safety') && $admin->has_cap('ritmo_view'),
            'cron' => $traces['cron'] !== [],
            'active' => is_plugin_active(RITMO_E2E_PLUGIN_FILE),
            'files' => is_file(WP_PLUGIN_DIR . '/ritmo-revenue/ritmo-revenue.php') && !is_link(WP_PLUGIN_DIR . '/ritmo-revenue'),
        ];
        foreach ($expect as $what => $ok) {
            if (!$ok) {
                $problems[] = "The installed plugin has no $what.";
            }
        }
        ritmo_e2e_finish(['counts' => array_map('count', $traces)], $problems);

    case 'residue':
        $file = $argv[3] ?? '';
        $before = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
        if (!is_array($before)) {
            fwrite(STDERR, $usage);
            exit(2);
        }
        foreach (ritmo_e2e_plugin_traces() as $what => $left) {
            if ($left !== []) {
                $problems[] = "Left after the uninstall ($what): " . implode(', ', $left);
            }
        }
        $after = ritmo_e2e_snapshot();
        foreach (['tables', 'options', 'cron', 'active_plugins'] as $part) {
            $missing = array_values(array_diff($before[$part], $after[$part]));
            if ($missing !== []) {
                $problems[] = "Removed although it is not the plugin's ($part): " . implode(', ', $missing);
            }
        }
        foreach ($before['roles'] as $role => $caps) {
            if (($after['roles'][$role] ?? null) !== $caps) {
                $problems[] = "The role $role changed: " . json_encode(['before' => $caps, 'after' => $after['roles'][$role] ?? null]);
            }
        }
        foreach ($before['users'] as $login => $user) {
            if (!isset($after['users'][$login])) {
                $problems[] = "The user $login was removed.";
                continue;
            }
            $lostMeta = array_values(array_diff($user['meta'], $after['users'][$login]['meta']));
            $lostCaps = array_values(array_diff($user['caps'], $after['users'][$login]['caps']));
            if ($lostMeta !== [] || $lostCaps !== []) {
                $problems[] = "The user $login lost " . json_encode(['meta' => $lostMeta, 'capabilities' => $lostCaps]);
            }
        }
        global $wpdb;
        $editor = get_user_by('login', RITMO_E2E_SENTINEL_USER);
        $kept = [
            'option' => get_option(RITMO_E2E_SENTINEL_OPTION) === 'kept',
            'table row' => $wpdb->get_var('SELECT label FROM ' . $wpdb->prefix . RITMO_E2E_SENTINEL_TABLE . ' WHERE id = 1') === 'kept',
            'user meta' => $editor instanceof WP_User && get_user_meta($editor->ID, RITMO_E2E_SENTINEL_META, true) === 'kept',
            'role' => get_role(RITMO_E2E_SENTINEL_ROLE) instanceof WP_Role && get_role(RITMO_E2E_SENTINEL_ROLE)->has_cap('e2e_keep_cap'),
            'cron event' => wp_next_scheduled(RITMO_E2E_SENTINEL_HOOK) !== false,
            'administrator' => get_role('administrator') instanceof WP_Role && get_role('administrator')->has_cap('manage_options'),
            'SQLite plugin active' => is_plugin_active('sqlite-database-integration/load.php'),
        ];
        foreach ($kept as $what => $ok) {
            if (!$ok) {
                $problems[] = "Not kept: $what.";
            }
        }
        ritmo_e2e_finish(['tables' => count($after['tables']), 'options' => count($after['options'])], $problems);
}
