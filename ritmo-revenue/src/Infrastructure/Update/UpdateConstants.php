<?php

declare(strict_types=1);

namespace Ritmo\Infrastructure\Update;

use InvalidArgumentException;
use Ritmo\Application\Update\TrustedKeys;
use Ritmo\Application\Update\UpdateSettings;
use Ritmo\Connectors\Licence\ServerUrl;

/**
 * The update channel's settings from the plugin and wp-config.php.
 *
 * The plugin embeds the address and the public key of its update server (EMBEDDED_URL,
 * EMBEDDED_PUBLIC_KEY; both empty until a server is chosen, which keeps the channel off). A site
 * may replace them with the constants RITMO_UPDATE_URL and RITMO_UPDATE_PUBLIC_KEY. A public key
 * that cannot be read turns the channel off; it never falls back to accepting unsigned updates.
 */
final class UpdateConstants
{
    /** The URL of the signed release metadata of the plugin's own update server. */
    public const EMBEDDED_URL = '';

    /** The base64 Ed25519 public key(s) the plugin's releases are signed with. */
    public const EMBEDDED_PUBLIC_KEY = '';

    public const URL_CONSTANT = 'RITMO_UPDATE_URL';

    public const KEY_CONSTANT = 'RITMO_UPDATE_PUBLIC_KEY';

    public const SLUG = 'ritmo-revenue';

    private function __construct()
    {
    }

    public static function settings(string $installedVersion, string $basename): UpdateSettings
    {
        $devMode = self::flag('RITMO_DEV') || self::flag('RITMO_LICENCE_DEV');
        $url = defined(self::URL_CONSTANT) ? constant(self::URL_CONSTANT) : self::EMBEDDED_URL;
        $keys = defined(self::KEY_CONSTANT) ? constant(self::KEY_CONSTANT) : self::EMBEDDED_PUBLIC_KEY;

        return self::from($url, $keys, $installedVersion, $basename, $devMode);
    }

    /**
     * The settings from given values (the constants, or a test's).
     */
    public static function from(mixed $url, mixed $keys, string $installedVersion, string $basename, bool $devMode): UpdateSettings
    {
        try {
            $trusted = TrustedKeys::parse(is_string($keys) ? $keys : '');
        } catch (InvalidArgumentException) {
            $trusted = TrustedKeys::of([]);
        }
        // The product's slug, whatever folder the site installed the plugin in.
        return new UpdateSettings(ServerUrl::tryParse($url, $devMode), $trusted, $installedVersion, self::SLUG, $basename, $devMode);
    }

    private static function flag(string $constant): bool
    {
        return defined($constant) && constant($constant) === true;
    }
}
