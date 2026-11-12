<?php

declare(strict_types=1);

namespace Ritmo\WordPress;

use Closure;
use Ritmo\Contracts\KeyValueStore;
use Ritmo\Infrastructure\Db\Migration\Migrator;
use Ritmo\Infrastructure\Db\WpdbDatabase;
use Ritmo\Infrastructure\Store\OptionsStore;

/**
 * Uninstall: removes the plugin's tables, options, role and capabilities, but only when the
 * site chose "delete data on uninstall" (Diagnostics). Otherwise it leaves everything, so that
 * deleting the plugin by mistake loses nothing.
 */
final class Uninstaller
{
    /** Settings key of the choice; off unless a person turned it on. */
    public const DELETE_DATA = 'delete_data_on_uninstall';

    /**
     * @param Closure(): void $removeData drops the tables and options and removes the role
     */
    public function __construct(private readonly KeyValueStore $settings, private readonly Closure $removeData)
    {
    }

    public static function forSite(): self
    {
        $settings = new OptionsStore();

        return new self($settings, static function () use ($settings): void {
            global $wpdb;
            Migrator::standard(new WpdbDatabase($wpdb), $settings)->uninstall();
            $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like('ritmo_') . '%'));
            Roles::remove();
            Scheduler::unschedule();
        });
    }

    /**
     * @return bool true when the data was removed
     */
    public function run(): bool
    {
        if ($this->settings->get(self::DELETE_DATA, false) !== true) {
            return false;
        }
        ($this->removeData)();

        return true;
    }
}
