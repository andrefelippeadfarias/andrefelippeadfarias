<?php

declare(strict_types=1);

namespace Ritmo\Application\Licence;

use Ritmo\Connectors\Licence\LicenceSignature;
use Ritmo\Connectors\Licence\ServerUrl;
use Ritmo\Domain\Licence\GracePolicy;

/**
 * The licence settings of a site. The licence server and the public key that signs its answers
 * are embedded in the plugin (EMBEDDED_SERVER_URL, EMBEDDED_PUBLIC_KEYS); nothing stored in the
 * database can redirect the key, lengthen the grace period or make a licence valid.
 *
 * The constants of wp-config.php that change this are honoured only for development, on a site
 * whose address is a loopback host (127.0.0.1, localhost, ::1) and with RITMO_DEV or
 * RITMO_LICENCE_DEV set to true:
 *
 * - RITMO_LICENCE_SERVER_URL   another licence server (http allowed for a loopback simulator)
 * - RITMO_LICENCE_PUBLIC_KEY   the base64 Ed25519 public key of that server
 * - RITMO_LICENCE_DEV          with RITMO_DEV as well: the test provider answers keys starting with "TEST-"
 *
 * RITMO_LICENCE_GRACE_DAYS (1 to 90 days, 30 by default) counts on every site.
 */
final class LicenceSettings
{
    public const SERVER_CONSTANT = 'RITMO_LICENCE_SERVER_URL';

    public const PUBLIC_KEY_CONSTANT = 'RITMO_LICENCE_PUBLIC_KEY';

    public const GRACE_CONSTANT = 'RITMO_LICENCE_GRACE_DAYS';

    public const DEV_CONSTANT = 'RITMO_LICENCE_DEV';

    /** The plugin's licence server; empty until a licensing platform is chosen. */
    public const EMBEDDED_SERVER_URL = '';

    /** Base64 Ed25519 public key(s) of that server, separated by commas; empty until then. */
    public const EMBEDDED_PUBLIC_KEYS = '';

    private const LOOPBACK = ['127.0.0.1', 'localhost', '::1', '[::1]'];

    /**
     * @param bool         $devMode       the test provider is accepted (RITMO_DEV and RITMO_LICENCE_DEV true, loopback site)
     * @param bool         $serverInvalid RITMO_LICENCE_SERVER_URL is set but cannot be used (not a valid address,
     *                                    not a development site, or no public key to check its answers)
     * @param list<string> $publicKeys    raw Ed25519 public keys that sign the server's "valid" answers
     */
    public function __construct(
        public readonly ?ServerUrl $server,
        public readonly GracePolicy $grace,
        public readonly bool $devMode,
        public readonly string $siteUrl,
        public readonly string $pluginVersion = '',
        public readonly string $product = 'ritmo-revenue',
        public readonly bool $serverInvalid = false,
        public readonly array $publicKeys = [],
    ) {
    }

    /**
     * The settings of this site from the embedded values and, on a development site, its constants.
     */
    public static function fromConstants(string $siteUrl, string $pluginVersion): self
    {
        $local = self::isLoopback($siteUrl);
        $development = $local && (self::flag('RITMO_DEV') || self::flag(self::DEV_CONSTANT));
        $text = defined(self::SERVER_CONSTANT) ? constant(self::SERVER_CONSTANT) : null;
        $configured = is_string($text) && trim($text) !== '';
        $keys = LicenceSignature::keys(self::EMBEDDED_PUBLIC_KEYS);
        if ($development && defined(self::PUBLIC_KEY_CONSTANT) && is_string(constant(self::PUBLIC_KEY_CONSTANT))) {
            $keys = array_values(array_unique([...$keys, ...LicenceSignature::keys((string) constant(self::PUBLIC_KEY_CONSTANT))]));
        }
        $server = $development && $configured ? ServerUrl::tryParse($text, true) : ServerUrl::tryParse(self::EMBEDDED_SERVER_URL, false);
        if ($keys === []) {
            $server = null;
        }

        return new self(
            $server,
            GracePolicy::fromSetting(defined(self::GRACE_CONSTANT) ? constant(self::GRACE_CONSTANT) : null),
            $local && self::flag('RITMO_DEV') && self::flag(self::DEV_CONSTANT),
            $siteUrl,
            $pluginVersion,
            'ritmo-revenue',
            $configured && ($server === null || !$development),
            $keys,
        );
    }

    /**
     * True for an address on 127.0.0.1, localhost (or a name under .localhost) or ::1.
     */
    public static function isLoopback(string $url): bool
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        return in_array($host, self::LOOPBACK, true) || str_ends_with($host, '.localhost');
    }

    private static function flag(string $constant): bool
    {
        return defined($constant) && constant($constant) === true;
    }
}
