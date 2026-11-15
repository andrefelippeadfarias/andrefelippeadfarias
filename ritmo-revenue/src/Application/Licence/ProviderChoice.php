<?php

declare(strict_types=1);

namespace Ritmo\Application\Licence;

use Ritmo\Connectors\Licence\HttpLicenceServer;
use Ritmo\Connectors\Licence\LicenceServer;
use Ritmo\Connectors\Licence\NoLicenceServer;
use Ritmo\Connectors\Licence\StubLicenceProvider;
use Ritmo\Contracts\HttpClient;
use Ritmo\Domain\Licence\LicenceKey;
use Ritmo\Domain\Support\Clock;

/**
 * Which provider answers for a key: the test provider for a "TEST-" key in development mode (a
 * loopback site with RITMO_DEV and RITMO_LICENCE_DEV), else the licence server with the public key
 * that signs its answers (LicenceSettings), else nobody (the key stays "not checked").
 * Outside development mode a test key goes to the real server like any other key.
 */
final class ProviderChoice
{
    public function __construct(
        private readonly LicenceSettings $settings,
        private readonly HttpClient $http,
        private readonly Clock $clock,
    ) {
    }

    public function for(LicenceKey $key): LicenceServer
    {
        if ($this->settings->devMode && $key->isTestKey()) {
            return new StubLicenceProvider($this->clock);
        }
        if ($this->settings->server !== null && $this->settings->publicKeys !== []) {
            return new HttpLicenceServer($this->http, $this->settings->server, $this->clock, $this->settings->product, $this->settings->pluginVersion, $this->settings->publicKeys);
        }

        return new NoLicenceServer($this->clock);
    }
}
