<?php

declare(strict_types=1);

namespace Ritmo\Connectors\Licence;

use Ritmo\Contracts\LicenceState;
use Ritmo\Domain\Support\Clock;

/**
 * What answers when the site has no licence server configured (no RITMO_LICENCE_SERVER_URL):
 * never reached, so a saved key stays "not checked" and nothing is sent anywhere.
 */
final class NoLicenceServer implements LicenceServer
{
    public const ID = 'none';

    public function __construct(private readonly Clock $clock)
    {
    }

    public function id(): string
    {
        return self::ID;
    }

    public function check(string $licenceKey, string $siteUrl): LicenceState
    {
        return new LicenceState(LicenceState::UNREACHABLE, null, $this->clock->now(), 'No licence server is configured.');
    }

    public function activate(string $licenceKey, string $siteUrl): LicenceState
    {
        return $this->check($licenceKey, $siteUrl);
    }

    public function deactivate(string $licenceKey, string $siteUrl): bool
    {
        return false;
    }
}
