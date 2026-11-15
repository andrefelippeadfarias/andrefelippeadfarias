<?php

declare(strict_types=1);

namespace Ritmo\Tests\Unit\WordPress;

use PHPUnit\Framework\TestCase;

/**
 * A multisite network: network activation is refused before anything is created, a plugin left
 * active for the whole network installs and runs nothing, and the uninstall reaches every site.
 * Each case runs in its own PHP process with the few WordPress functions it needs.
 */
final class MultisiteTest extends TestCase
{
    private const COMMON = 'define("ABSPATH", "/"); define("RITMO_FILE", "/wp/wp-content/plugins/ritmo-revenue/ritmo-revenue.php");'
        . 'function is_multisite() { return true; }'
        . 'function esc_html__($t, $d) { return $t; }'
        . 'function __($t, $d) { return $t; }';

    public function testNetworkActivationIsRefusedBeforeAnythingIsCreated(): void
    {
        [$exit, $output] = self::runPhp(self::COMMON
            . '$log = [];'
            . 'function deactivate_plugins($p, $silent, $network) { global $log; $log[] = "deactivated " . $p . " network=" . var_export($network, true); }'
            . 'function wp_die($message, $title, $args) { global $log; echo implode("|", $log) . "|" . $message; exit(0); }'
            . 'require ' . var_export(self::root() . '/src/autoload.php', true) . ';'
            . '\Ritmo\WordPress\Activator::activate(true);'
            . 'echo "CREATED";');

        self::assertSame(0, $exit, $output);
        self::assertStringStartsWith('deactivated ritmo-revenue/ritmo-revenue.php network=true|Ritmo Revenue cannot be activated for the whole network.', $output);
        self::assertStringNotContainsString('CREATED', $output);
    }

    public function testAPluginActiveForTheWholeNetworkInstallsNothing(): void
    {
        [$exit, $output] = self::runPhp(self::COMMON
            . '$hooks = [];'
            . 'function get_site_option($k, $d = false) { return $k === "active_sitewide_plugins" ? ["ritmo-revenue/ritmo-revenue.php" => 1] : $d; }'
            . 'function add_action($h, $c, $p = 10, $a = 1) { global $hooks; $hooks[] = $h; return true; }'
            . 'function add_filter($h, $c, $p = 10, $a = 1) { global $hooks; $hooks[] = $h; return true; }'
            . 'require ' . var_export(self::root() . '/src/autoload.php', true) . ';'
            . '\Ritmo\Plugin::boot();'
            . 'echo json_encode($hooks);');

        self::assertSame(0, $exit, $output);
        self::assertSame('["init","network_admin_notices","admin_notices"]', $output, 'no upgrade, no menu, no schedule, no REST route');
    }

    public function testTheUninstallReachesEverySite(): void
    {
        [$exit, $output] = self::runPhp(self::COMMON
            . 'define("WP_UNINSTALL_PLUGIN", "ritmo-revenue/ritmo-revenue.php"); $blog = 1; $seen = [];'
            . 'function get_current_blog_id() { global $blog; return $blog; }'
            . 'function get_sites($a) { return [1, 2, 3]; }'
            . 'function switch_to_blog($id) { global $blog; $blog = $id; }'
            . 'function restore_current_blog() { global $blog; $blog = 1; }'
            . 'function get_option($name, $default = false) { return $default; }'
            . 'function delete_option($name) { global $blog, $seen; $seen[] = $blog . ":" . $name; return true; }'
            . 'require ' . var_export(self::root() . '/uninstall.php', true) . ';'
            . 'echo json_encode($seen);');

        self::assertSame(0, $exit, $output);
        self::assertSame('["1:ritmo_vault","2:ritmo_vault","3:ritmo_vault"]', $output, 'each site loses its secrets (and its data when it chose so)');
    }

    /**
     * @return array{0: int, 1: string}
     */
    private static function runPhp(string $code): array
    {
        $output = [];
        $exit = 1;
        exec(escapeshellarg(PHP_BINARY) . ' -d display_errors=1 -d error_reporting=-1 -r ' . escapeshellarg($code) . ' 2>&1', $output, $exit);

        return [$exit, implode("\n", $output)];
    }

    private static function root(): string
    {
        return dirname(__DIR__, 3);
    }
}
