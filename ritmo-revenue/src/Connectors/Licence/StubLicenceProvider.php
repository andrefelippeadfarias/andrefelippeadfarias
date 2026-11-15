<?php

declare(strict_types=1);

namespace Ritmo\Connectors\Licence;

use DateInterval;
use Ritmo\Contracts\LicenceState;
use Ritmo\Domain\Licence\LicenceKey;
use Ritmo\Domain\Licence\LicenceRecord;
use Ritmo\Domain\Support\Clock;

/**
 * The test provider: answers from the key itself, without any network. Its answers count only
 * while the constant RITMO_LICENCE_DEV is true (LicenceEvaluator); the plugin offers it only then.
 *
 *   TEST-VALID-...    valid for one year from now
 *   TEST-EXPIRED-...  expired yesterday
 *   TEST-DOWN-...     the provider cannot be reached
 *   anything else     invalid
 */
final class StubLicenceProvider implements LicenceServer
{
    public const ID = LicenceRecord::TEST_PROVIDER;

    public function __construct(private readonly Clock $clock)
    {
    }

    public function id(): string
    {
        return self::ID;
    }

    public function check(string $licenceKey, string $siteUrl): LicenceState
    {
        $now = $this->clock->now();
        $key = strtoupper(trim($licenceKey));

        return match (true) {
            str_starts_with($key, LicenceKey::TEST_PREFIX . 'VALID-') => new LicenceState(LicenceState::VALID, $now->add(new DateInterval('P365D')), $now, 'Test licence.'),
            str_starts_with($key, LicenceKey::TEST_PREFIX . 'EXPIRED-') => new LicenceState(LicenceState::EXPIRED, $now->sub(new DateInterval('P1D')), $now, 'Test licence, expired.'),
            str_starts_with($key, LicenceKey::TEST_PREFIX . 'DOWN-') => new LicenceState(LicenceState::UNREACHABLE, null, $now, 'The test provider is down.'),
            default => new LicenceState(LicenceState::INVALID, null, $now, 'Unknown test licence.'),
        };
    }

    public function activate(string $licenceKey, string $siteUrl): LicenceState
    {
        return $this->check($licenceKey, $siteUrl);
    }

    public function deactivate(string $licenceKey, string $siteUrl): bool
    {
        return $this->check($licenceKey, $siteUrl)->status !== LicenceState::UNREACHABLE;
    }
}
