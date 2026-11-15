<?php

declare(strict_types=1);

namespace Ritmo\Application\Licence;

use Ritmo\Connectors\Licence\ServerUrl;
use Ritmo\Domain\Licence\GracePolicy;

/**
 * The licence settings of a site, from the constants of wp-config.php (never from stored
 * settings, so nothing in the database can redirect the key or lengthen the grace period):
 *
 * - RITMO_LICENCE_SERVER_URL  the licence server (https; http only for a loopback host in development mode)
 * - RITMO_LICENCE_GRACE_DAYS  the grace period, 1 to 90 days (30 by default)
 * - RITMO_LICENCE_DEV         true: the test provider answers keys starting with "TEST-" (off by default)
 */
final class LicenceSettings
{
    public const SERVER_CONSTANT = 'RITMO_LICENCE_SERVER_URL';

    public const GRACE_CONSTANT = 'RITMO_LICENCE_GRACE_DAYS';

    public const DEV_CONSTANT = 'RITMO_LICENCE_DEV';

    /**
     * @param bool $devMode       the test provider is accepted (RITMO_LICENCE_DEV === true)
     * @param bool $serverInvalid RITMO_LICENCE_SERVER_URL is set but is not a valid address
     */
    public function __construct(
        public readonly ?ServerUrl $server,
        public readonly GracePolicy $grace,
        public readonly bool $devMode,
        public readonly string $siteUrl,
        public readonly string $pluginVersion = '',
        public readonly string $product = 'ritmo-revenue',
        public readonly bool $serverInvalid = false,
    ) {
    }

    /**
     * The settings of this site from its constants. RITMO_DEV or RITMO_LICENCE_DEV let the server
     * address be plain http on a loopback host (the local simulator).
     */
    public static function fromConstants(string $siteUrl, string $pluginVersion): self
    {
        $devMode = self::flag(self::DEV_CONSTANT);
        $text = defined(self::SERVER_CONSTANT) ? constant(self::SERVER_CONSTANT) : null;
        $server = ServerUrl::tryParse($text, $devMode || self::flag('RITMO_DEV'));
        $configured = is_string($text) && trim($text) !== '';

        return new self(
            $server,
            GracePolicy::fromSetting(defined(self::GRACE_CONSTANT) ? constant(self::GRACE_CONSTANT) : null),
            $devMode,
            $siteUrl,
            $pluginVersion,
            'ritmo-revenue',
            $configured && $server === null,
        );
    }

    private static function flag(string $constant): bool
    {
        return defined($constant) && constant($constant) === true;
    }
}
