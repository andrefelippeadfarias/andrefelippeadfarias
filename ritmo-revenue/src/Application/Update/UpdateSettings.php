<?php

declare(strict_types=1);

namespace Ritmo\Application\Update;

use Ritmo\Connectors\Licence\ServerUrl;

/**
 * The update channel of a site, read once from the plugin and the constants of wp-config.php by
 * the WordPress side (Infrastructure\Update\UpdateConstants):
 *
 * - RITMO_UPDATE_URL         the URL of the signed release metadata (https; http only for a loopback host in development mode)
 * - RITMO_UPDATE_PUBLIC_KEY  the trusted Ed25519 public keys (base64), replacing the key embedded in the plugin
 *
 * Without an address or a trusted key the channel is off: nothing is asked and nothing is offered.
 */
final class UpdateSettings
{
    public const MAX_PACKAGE_BYTES = 30 * 1024 * 1024;

    /**
     * @param string $basename the plugin's file relative to the plugins folder ("ritmo-revenue/ritmo-revenue.php")
     */
    public function __construct(
        public readonly ?ServerUrl $metadataUrl,
        public readonly TrustedKeys $keys,
        public readonly string $installedVersion,
        public readonly string $slug = 'ritmo-revenue',
        public readonly string $basename = 'ritmo-revenue/ritmo-revenue.php',
        public readonly bool $devMode = false,
        public readonly int $maxPackageBytes = self::MAX_PACKAGE_BYTES,
    ) {
    }

    public function enabled(): bool
    {
        return $this->metadataUrl !== null && !$this->keys->isEmpty();
    }
}
