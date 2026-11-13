<?php
/**
 * Checks on the throwaway site that need WordPress itself and more than one PHP process. Run by
 * bin/e2e.sh after shadow-run.js, while the simulator is up and the fake keys are saved:
 *
 *   php tests/e2e/wordpress-checks.php <wordpress folder>
 *
 * - the emergency switch engaged by another process is seen by a request that already read it,
 *   and the run that request left open is closed as stopped;
 * - the saved keys are kept with nothing of them in clear, and the database alone (the stored
 *   seed) decrypts none of them;
 * - an activation does not give back a capability the site took away;
 * - a RITMO_SECRET_KEY that is too short breaks nothing: activation, the screens and the run
 *   go on, and say what is wrong.
 *
 * Checks that need a constant of their own run in a child process of this script, because a
 * constant cannot be undefined. Exit code 0 when every check passed.
 */

declare(strict_types=1);

use Ritmo\Admin\AdminScreens;
use Ritmo\Admin\Components\Menu;
use Ritmo\Application\ShadowRun\RunOutcome;
use Ritmo\Infrastructure\Db\Repository\RunRepository;
use Ritmo\Infrastructure\Secrets\SodiumVault;
use Ritmo\WordPress\ConnectorFactory;
use Ritmo\WordPress\Roles;
use Ritmo\WordPress\Services;

const E2E_PROPERTY = 'example-inn';
const E2E_ACTIVATION = 'activate_ritmo-revenue/ritmo-revenue.php';

if (PHP_SAPI !== 'cli' || !isset($argv[1]) || !is_file($argv[1] . '/wp-load.php')) {
    fwrite(STDERR, "Usage: php tests/e2e/wordpress-checks.php <wordpress folder>\n");
    exit(2);
}
$site = rtrim($argv[1], '/');
$mode = $argv[2] ?? 'main';

if ($mode === 'short-key') {
    define('RITMO_SECRET_KEY', 'too-short-key');
}
require $site . '/wp-load.php';

$checks = 0;

function check(bool $ok, string $what): void
{
    global $checks;
    if (!$ok) {
        fwrite(STDERR, "FAILED: $what\n");
        exit(1);
    }
    ++$checks;
}

/**
 * Runs this script in another PHP process and returns what it printed.
 */
function child(string $site, string $mode): string
{
    $command = sprintf('%s %s %s %s 2>&1', escapeshellarg(PHP_BINARY), escapeshellarg(__FILE__), escapeshellarg($site), escapeshellarg($mode));
    exec($command, $output, $code);
    $printed = implode("\n", $output);
    check($code === 0, "child process \"$mode\": $printed");

    return $printed;
}

/**
 * Renders a tab of the Settings screen of the plugin as the given user.
 */
function screen(string $tab, int $userId): string
{
    require_once ABSPATH . 'wp-admin/includes/admin.php';
    wp_set_current_user($userId);
    $_GET['page'] = Menu::SETTINGS;
    $_GET['tab'] = $tab;
    ob_start();
    (AdminScreens::renderers()[Menu::SETTINGS])();

    return (string) ob_get_clean();
}

function administratorId(): int
{
    $users = get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ID']);

    return (int) ($users[0] ?? 0);
}

switch ($mode) {
    case 'engage':
        Services::instance()->emergencySwitch()->engage();
        echo "engaged\n";
        exit(0);

    case 'short-key':
        do_action(E2E_ACTIVATION);
        $services = Services::instance();
        check($services->vault()->protection() === SodiumVault::PROTECTION_UNAVAILABLE, 'a short RITMO_SECRET_KEY makes the vault unavailable');
        check(str_contains((string) $services->vault()->problem(), 'at least 32 characters'), 'the problem is explained');
        $test = $services->connectors()->test('beds24');
        check(!$test->ok && str_contains($test->message, 'RITMO_SECRET_KEY'), 'the connection test says what is wrong: ' . $test->message);
        $admin = administratorId();
        check(str_contains(screen('connections', $admin), 'data-ritmo="connection-test-beds24"'), 'the Connections tab opens');
        check(str_contains(screen('diagnostics', $admin), 'shorter than 32 characters'), 'Diagnostics names the problem');
        ob_start();
        AdminScreens::vaultNotice();
        check(str_contains((string) ob_get_clean(), 'data-ritmo="vault-problem"'), 'an admin notice reports the problem');
        $outcome = $services->shadowRuns()->run(E2E_PROPERTY, 60);
        check($outcome->status === RunOutcome::FAILED && str_contains((string) $outcome->error, 'RITMO_SECRET_KEY'), 'the run fails with the reason: ' . $outcome->status . ' ' . $outcome->error);
        echo "short key ok\n";
        exit(0);

    case 'main':
        break;

    default:
        fwrite(STDERR, "Unknown mode $mode\n");
        exit(2);
}

$services = Services::instance();

// 1. The emergency switch across processes.
$switch = $services->emergencySwitch();
$switch->release();
check(!$switch->isEngaged(), 'the switch starts released');
$open = $services->shadowRuns()->run(E2E_PROPERTY, 0);
check($open->status === RunOutcome::RUNNING && $open->stage === 'collect', 'a run is left open after its first stage: ' . $open->status);
child($site, 'engage');
check($switch->isEngaged(), 'the switch engaged by another process is seen by a request that read it before');
$blocked = $services->shadowRuns()->run(E2E_PROPERTY, 60);
check($blocked->status === RunOutcome::BLOCKED && $blocked->runId === $open->runId, 'the run is blocked: ' . $blocked->status);
check(($services->runs()->find((int) $open->runId)['status'] ?? null) === RunRepository::STOPPED, 'the open run is closed as stopped');
$switch->release();
check(!$switch->isEngaged(), 'released again');

// 2. The keys at rest.
check($services->vault()->protection() === SodiumVault::PROTECTION_SITE_SALTS, 'without RITMO_SECRET_KEY the key is bound to the salts');
$pdo = new PDO('sqlite:' . $site . '/wp-content/database/e2e.sqlite');
$stored = json_decode((string) $pdo->query("SELECT option_value FROM wp_options WHERE option_name = 'ritmo_vault'")->fetchColumn(), true);
check(is_array($stored) && count($stored['entries'] ?? []) === 2, 'both keys are stored');
foreach ($stored['entries'] as $name => $entry) {
    check(array_keys($entry) === ['k', 'n', 'c', 'u'], "$name keeps nothing of the key in clear");
    $seedOnly = sodium_crypto_generichash("ritmo-revenue/vault/v1\0" . base64_decode((string) $stored['seed']), '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
    $entryKey = sodium_crypto_generichash('entry:' . $name, $seedOnly, SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
    check(sodium_crypto_secretbox_open(base64_decode($entry['c']), base64_decode($entry['n']), $entryKey) === false, "$name cannot be decrypted from the database alone");
}
check($services->connectors()->test('beds24')->ok, 'the site itself still reads the keys');

// 3. Capabilities taken away stay away.
get_role(Roles::ROLE)?->remove_cap(Roles::MANAGE_CONNECTIONS);
get_role('administrator')?->remove_cap(Roles::MANAGE_CONNECTIONS);
do_action(E2E_ACTIVATION);
check(get_role(Roles::ROLE) !== null && !get_role(Roles::ROLE)->has_cap(Roles::MANAGE_CONNECTIONS), 'the role does not get the capability back');
check(!get_role('administrator')->has_cap(Roles::MANAGE_CONNECTIONS), 'administrators do not get it back');
check(get_role(Roles::ROLE)->has_cap(Roles::VIEW), 'the other capabilities stay');
get_role(Roles::ROLE)->add_cap(Roles::MANAGE_CONNECTIONS);
get_role('administrator')->add_cap(Roles::MANAGE_CONNECTIONS);

// 4. A RITMO_SECRET_KEY that is too short.
child($site, 'short-key');
check($services->vault()->reveal(ConnectorFactory::BEDS24_SECRET) !== '', 'the keys are untouched by the process with the short key');

echo "WordPress checks OK ($checks checks).\n";
