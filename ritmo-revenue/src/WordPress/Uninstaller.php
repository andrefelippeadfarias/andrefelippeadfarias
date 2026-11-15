<?php

declare(strict_types=1);

namespace Ritmo\WordPress;

use Closure;
use Ritmo\Admin\Licence\LicenceHooks;
use Ritmo\Admin\Licence\LicenceServices;
use Ritmo\Contracts\KeyValueStore;
use Ritmo\Infrastructure\Db\Migration\Migrator;
use Ritmo\Infrastructure\Db\WpdbDatabase;
use Ritmo\Infrastructure\Store\OptionsStore;
use Throwable;

/**
 * Uninstall: frees the licence key on its provider, then removes the plugin's tables, settings,
 * transients, user meta, both roles, the six capabilities and the scheduled events, but only when
 * the site chose "delete data on uninstall" (Settings, uninstall).
 * Otherwise it keeps the data, so that deleting the plugin by mistake loses no history, but never
 * the secrets: the licence is freed and the vault (the PMS and pricing system tokens, the Claude
 * key, the licence key and the vault's own seed) is always deleted.
 */
final class Uninstaller
{
    /** Settings key of the choice; off unless a person turned it on. */
    public const DELETE_DATA = 'delete_data_on_uninstall';

    /** Settings key of the secrets vault (SodiumVault): entries and seed. */
    public const VAULT = 'vault';

    /**
     * @param Closure(): void      $removeData    drops the tables and options and removes the role
     * @param (Closure(): void)|null $removeSecrets frees the licence and deletes the vault (data kept)
     */
    public function __construct(private readonly KeyValueStore $settings, private readonly Closure $removeData, private readonly ?Closure $removeSecrets = null)
    {
    }

    /**
     * @param bool $freeLicence frees the licence key on its provider (the current site only: the
     *                          licence services are built once per request)
     */
    public static function forSite(bool $freeLicence = true): self
    {
        $settings = new OptionsStore();

        $removeSecrets = static function () use ($settings, $freeLicence): void {
            if ($freeLicence) {
                self::freeLicence();
            }
            $settings->delete(self::VAULT);
        };

        return new self($settings, static function () use ($settings, $freeLicence): void {
            global $wpdb;
            if ($freeLicence) {
                self::freeLicence();
            }
            Migrator::standard(new WpdbDatabase($wpdb), $settings)->uninstall();
            // Settings (ritmo_*: the vault, the licence record, the assistant's settings, usage and
            // drafts, the update metadata), and the transients of the screens (CSV previews, form
            // errors, the assistant's answers), site-wide ones included.
            foreach (['ritmo_', '_transient_ritmo_', '_transient_timeout_ritmo_', '_site_transient_ritmo_', '_site_transient_timeout_ritmo_'] as $prefix) {
                $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like($prefix) . '%'));
            }
            // Per user: the property chosen, the alert e-mail choice, the rules draft.
            $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->usermeta} WHERE meta_key LIKE %s", $wpdb->esc_like('ritmo_') . '%'));
            Roles::remove();
            Scheduler::unschedule();
            LicenceHooks::unschedule();
        }, $removeSecrets);
    }

    /**
     * Frees the licence key's seat on its provider before its record goes (one attempt, at most the
     * provider's timeout; a failure never stops the uninstall). No key, no request.
     */
    private static function freeLicence(): void
    {
        try {
            // WordPress runs uninstall.php without the plugin's main file: the constants the
            // licence service reads (the version comes from the plugin header).
            defined('RITMO_FILE') || define('RITMO_FILE', dirname(__DIR__, 2) . '/ritmo-revenue.php');
            defined('RITMO_VERSION') || define('RITMO_VERSION', (string) (get_file_data(RITMO_FILE, ['Version' => 'Version'])['Version'] ?? ''));
            LicenceServices::instance()->licence()->remove();
        } catch (Throwable) {
            return;
        }
    }

    /**
     * Uninstall on every site that has the plugin's data: the current site, and on a multisite
     * network each other site in turn (activated site by site, each one has its own tables,
     * settings, roles and schedule).
     */
    public static function everywhere(): void
    {
        self::forSite()->run();
        if (!function_exists('is_multisite') || !is_multisite() || !function_exists('get_sites')) {
            return;
        }
        $current = get_current_blog_id();
        foreach (get_sites(['fields' => 'ids', 'number' => 0]) as $siteId) {
            if ((int) $siteId === $current) {
                continue;
            }
            switch_to_blog((int) $siteId);
            try {
                self::forSite(false)->run();
            } finally {
                restore_current_blog();
            }
        }
    }

    /**
     * @return bool true when the data was removed
     */
    public function run(): bool
    {
        if ($this->settings->get(self::DELETE_DATA, false) !== true) {
            if ($this->removeSecrets !== null) {
                ($this->removeSecrets)();
            }

            return false;
        }
        ($this->removeData)();

        return true;
    }
}
