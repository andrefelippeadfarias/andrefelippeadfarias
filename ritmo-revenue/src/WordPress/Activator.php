<?php

declare(strict_types=1);

namespace Ritmo\WordPress;

use Ritmo\Domain\Config\InvalidConfig;
use Ritmo\Domain\Config\RuleSet;

/**
 * Activation and update: schedules the scheduler tick (and clears the event of earlier
 * versions) first, then creates or updates the tables, prepares the secrets vault, installs the
 * role and the capabilities, and stores the neutral rules when there are none yet or completes
 * the stored ones with the settings an update added. Each step stands on its own: rules that do
 * not pass validation are logged and leave the rest done (the screens show the problem; no run
 * starts on them). Safe to run again. On a multisite network it runs site by site: network
 * activation is refused (networkActivated()).
 */
final class Activator
{
    /** The note of the first version of the rules (translated when shown: VersionsView::note()). */
    public const FIRST_VERSION_NOTE = 'Neutral defaults, stored at activation';

    private function __construct()
    {
    }

    /**
     * @param mixed $networkWide what WordPress passes on activation: true when a network admin
     *                           activates the plugin for every site of a multisite network
     */
    public static function activate(mixed $networkWide = false): void
    {
        if ($networkWide === true && function_exists('is_multisite') && is_multisite()) {
            self::refuseNetwork();
        }
        $services = Services::instance();
        Scheduler::schedule();
        $services->migrator()->migrate();
        $services->vault()->install();
        Roles::install($services->store());
        try {
            if ($services->ruleSets()->currentVersion() === null) {
                $services->ruleSets()->save(RuleSet::defaults(), self::FIRST_VERSION_NOTE);
            } else {
                $services->ruleSets()->upgradeCurrent();
            }
        } catch (InvalidConfig $invalid) {
            $services->logger()->error('The stored rules do not pass validation after the update.', ['errors' => count($invalid->errors())]);
        }
    }

    /**
     * True on a multisite network where the plugin is active for the whole network (its data,
     * schedule and roles are per site: it runs only when activated site by site).
     */
    public static function networkActivated(): bool
    {
        if (!function_exists('is_multisite') || !is_multisite() || !function_exists('get_site_option')) {
            return false;
        }
        $active = get_site_option('active_sitewide_plugins', []);

        return is_array($active) && isset($active[self::basename()]);
    }

    public static function basename(): string
    {
        return basename(dirname(RITMO_FILE)) . '/' . basename(RITMO_FILE);
    }

    /**
     * Network activation is refused before anything is created: the plugin is put back to
     * inactive and the network admin is told to activate it on each site.
     */
    private static function refuseNetwork(): void
    {
        if (function_exists('deactivate_plugins')) {
            deactivate_plugins(self::basename(), true, true);
        }
        wp_die(
            esc_html__('Ritmo Revenue cannot be activated for the whole network. Activate it site by site, in the Plugins screen of each site that uses it.', 'ritmo-revenue'),
            esc_html__('Activate site by site', 'ritmo-revenue'),
            ['back_link' => true, 'response' => 200]
        );
    }
}
