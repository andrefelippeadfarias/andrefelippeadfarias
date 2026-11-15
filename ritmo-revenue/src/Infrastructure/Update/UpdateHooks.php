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
    public function __construct(
        private readonly Closure $checker,
        private readonly Closure $refusal,
        private readonly string $basename = 'ritmo-revenue/ritmo-revenue.php',
        private readonly string $slug = 'ritmo-revenue',
        private readonly string $installedVersion = '',
    ) {
    }

    /**
     * @param Closure(): UpdateChecker $checker
     * @param Closure(string): string  $refusal
     * @param string                   $basename this plugin's basename, known without the checker (it may fail)
     */
    public static function register(Closure $checker, Closure $refusal, string $basename = 'ritmo-revenue/ritmo-revenue.php', string $slug = 'ritmo-revenue', string $installedVersion = ''): self
    {
        $hooks = new self($checker, $refusal, $basename, $slug, $installedVersion);
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
            // Never leave another offer for this plugin (a plugin of the same name elsewhere).
            if (isset($transient->response) && is_array($transient->response)) {
                $response = $transient->response;
                unset($response[$this->basename]);
                $transient->response = $response;
            }

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
        if ($action !== 'plugin_information' || !is_object($args) || ($args->slug ?? null) !== $this->slug) {
            return $result;
        }
        try {
            $checker = ($this->checker)();
            $settings = $checker->settings();
            $release = $checker->offer();
        } catch (Throwable) {
            return $this->localDetails();
        }
        if ($release === null) {
            return $this->localDetails();
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
        $forThisPlugin = is_array($hookExtra) && ($hookExtra['plugin'] ?? null) === $this->basename;
        try {
            $checker = ($this->checker)();
        } catch (Throwable) {
            // Nothing can be checked: a package for this plugin is refused, never installed unchecked.
            return $forThisPlugin ? new WP_Error('ritmo_update_refused', ($this->refusal)(UpdateRefused::UNREACHABLE)) : $reply;
        }
        $settings = $checker->settings();
        $forThisPlugin = $forThisPlugin || (is_array($hookExtra) && ($hookExtra['plugin'] ?? null) === $settings->basename);
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
     * The details window without a verified release: this plugin's own facts, so WordPress never
     * asks WordPress.org about a plugin of the same name.
     */
    private function localDetails(): object
    {
        return (object) [
            'name' => 'Ritmo Revenue',
            'slug' => $this->slug,
            'version' => $this->installedVersion,
            'requires' => '6.5',
            'requires_php' => '8.1',
            'sections' => ['description' => '<p>' . esc_html(($this->refusal)(UpdateRefused::NOT_CONFIGURED)) . '</p>'],
        ];
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
