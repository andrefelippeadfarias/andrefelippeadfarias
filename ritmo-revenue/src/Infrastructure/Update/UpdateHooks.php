<?php

declare(strict_types=1);

namespace Ritmo\Infrastructure\Update;

use Closure;
use Ritmo\Application\Update\ReleaseManifest;
use Ritmo\Application\Update\UpdateChecker;
use Ritmo\Application\Update\UpdateRefused;
use Throwable;
use WP_Error;

/**
 * The plugin's updates through WordPress's own updater:
 *
 * - pre_set_site_transient_update_plugins: offers the verified release (UpdateChecker::latest())
 *   and removes any other offer for this plugin (a plugin of the same name elsewhere);
 * - plugins_api: the "View details" window of the verified release;
 * - upgrader_pre_download: for this plugin (or a package of its update server) WordPress gets the
 *   package only after UpdateChecker::download() verified its size, hash and signature; anything
 *   else for this plugin is refused. The bytes are written to a temporary file for WordPress to
 *   unpack and install; nothing of them is run before that.
 */
final class UpdateHooks
{
    /**
     * @param Closure(): UpdateChecker $checker built on first use
     * @param Closure(string): string  $refusal the text shown for an UpdateRefused reason
     */
    public function __construct(private readonly Closure $checker, private readonly Closure $refusal)
    {
    }

    /**
     * @param Closure(): UpdateChecker $checker
     * @param Closure(string): string  $refusal
     */
    public static function register(Closure $checker, Closure $refusal): self
    {
        $hooks = new self($checker, $refusal);
        add_filter('pre_set_site_transient_update_plugins', [$hooks, 'offer']);
        add_filter('plugins_api', [$hooks, 'details'], 10, 3);
        add_filter('upgrader_pre_download', [$hooks, 'download'], 10, 4);

        return $hooks;
    }

    /**
     * @param mixed $transient the update_plugins transient being saved
     */
    public function offer(mixed $transient): mixed
    {
        if (!is_object($transient)) {
            return $transient;
        }
        try {
            $checker = ($this->checker)();
            $release = $checker->latest();
        } catch (Throwable) {
            return $transient;
        }
        $settings = $checker->settings();
        $basename = $settings->basename;
        $response = isset($transient->response) && is_array($transient->response) ? $transient->response : [];
        $noUpdate = isset($transient->no_update) && is_array($transient->no_update) ? $transient->no_update : [];
        unset($response[$basename], $noUpdate[$basename]);
        $item = [
            'id' => $basename,
            'slug' => $settings->slug,
            'plugin' => $basename,
            'url' => '',
            'icons' => [],
            'banners' => [],
            'banners_rtl' => [],
        ];
        if ($release === null) {
            $noUpdate[$basename] = (object) ($item + ['new_version' => $settings->installedVersion, 'package' => '']);
        } else {
            $response[$basename] = (object) ($item + [
                'new_version' => $release->version,
                'package' => $release->package,
                'requires' => $release->requires,
                'requires_php' => $release->requiresPhp,
                'tested' => $release->tested,
            ]);
        }
        $transient->response = $response;
        $transient->no_update = $noUpdate;

        return $transient;
    }

    /**
     * @param mixed $result what another filter answered (false for nobody)
     * @param mixed $action the plugins_api action
     * @param mixed $args   its arguments (an object with the slug)
     */
    public function details(mixed $result, mixed $action, mixed $args): mixed
    {
        try {
            $checker = ($this->checker)();
        } catch (Throwable) {
            return $result;
        }
        $settings = $checker->settings();
        if ($action !== 'plugin_information' || !is_object($args) || ($args->slug ?? null) !== $settings->slug) {
            return $result;
        }
        $release = $checker->offer();
        if ($release === null) {
            return $result;
        }

        return (object) [
            'name' => $release->name !== '' ? $release->name : 'Ritmo Revenue',
            'slug' => $settings->slug,
            'version' => $release->version,
            'requires' => $release->requires,
            'requires_php' => $release->requiresPhp,
            'tested' => $release->tested,
            'last_updated' => $release->released,
            'download_link' => $release->package,
            'sections' => ['changelog' => self::changelog($release)],
        ];
    }

    /**
     * @param mixed $reply     false, or what another filter already decided
     * @param mixed $package   the package URL WordPress is about to download
     * @param mixed $upgrader  the WP_Upgrader
     * @param mixed $hookExtra what is being upgraded (["plugin" => basename] for a plugin update)
     */
    public function download(mixed $reply, mixed $package, mixed $upgrader = null, mixed $hookExtra = []): mixed
    {
        if ($reply !== false || !is_string($package)) {
            return $reply;
        }
        try {
            $checker = ($this->checker)();
        } catch (Throwable) {
            return $reply;
        }
        $settings = $checker->settings();
        $forThisPlugin = is_array($hookExtra) && ($hookExtra['plugin'] ?? null) === $settings->basename;
        $onTheServer = $settings->metadataUrl !== null && $settings->metadataUrl->sameOrigin($package, $settings->devMode);
        if (!$forThisPlugin && !$onTheServer) {
            return $reply;
        }
        try {
            $bytes = $checker->download($package);
        } catch (UpdateRefused $refused) {
            return new WP_Error('ritmo_update_refused', ($this->refusal)($refused->reason));
        } catch (Throwable) {
            return new WP_Error('ritmo_update_refused', ($this->refusal)(UpdateRefused::UNREACHABLE));
        }
        $file = wp_tempnam('ritmo-revenue.zip');
        if (!is_string($file) || $file === '' || file_put_contents($file, $bytes) !== strlen($bytes)) {
            return new WP_Error('ritmo_update_refused', ($this->refusal)(UpdateRefused::UNREACHABLE));
        }

        return $file;
    }

    /**
     * The changelog as HTML for the details window: every line escaped.
     */
    private static function changelog(ReleaseManifest $release): string
    {
        $items = array_map(static fn (string $line): string => '<li>' . esc_html($line) . '</li>', $release->changelog);

        return '<h4>' . esc_html($release->version) . '</h4>' . ($items === [] ? '' : '<ul>' . implode('', $items) . '</ul>');
    }
}
