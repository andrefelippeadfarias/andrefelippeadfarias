<?php

declare(strict_types=1);

namespace Ritmo\Tests\Unit\Application\Licence;

use Closure;
use PHPUnit\Framework\TestCase;
use Ritmo\Application\Licence\LicenceRefused;
use Ritmo\Application\Licence\LicenceService;
use Ritmo\Application\Licence\LicenceSettings;
use Ritmo\Application\Licence\ProviderChoice;
use Ritmo\Connectors\Licence\ServerUrl;
use Ritmo\Contracts\Exception\ConnectorException;
use Ritmo\Contracts\HttpResponse;
use Ritmo\Domain\Licence\GracePolicy;
use Ritmo\Domain\Licence\LicenceReason;
use Ritmo\Domain\Licence\LicenceStanding;
use Ritmo\Infrastructure\Secrets\SodiumVault;
use Ritmo\Infrastructure\Store\ArrayStore;
use Ritmo\Tests\Fakes\MovableClock;
use Ritmo\Tests\Fakes\RecordingLogger;
use Ritmo\Tests\Fakes\ScriptedHttpClient;
use Ritmo\Tests\Fakes\SignedLicenceAnswers;

final class LicenceServiceTest extends TestCase
{
    private const KEY = 'RR-2026-ABCD-EFGH';

    private const SITE = 'https://inn.example.com';

    private MovableClock $clock;

    private static ?MovableClock $now = null;

    private ScriptedHttpClient $http;

    private ArrayStore $store;

    private SodiumVault $vault;

    private RecordingLogger $logger;

    protected function setUp(): void
    {
        $this->clock = new MovableClock('2026-11-15T10:00:00Z');
        self::$now = $this->clock;
        $this->http = new ScriptedHttpClient();
        $this->store = new ArrayStore();
        $this->vault = new SodiumVault($this->store, $this->clock, str_repeat('v', 40));
        $this->logger = new RecordingLogger();
    }

    private function service(bool $dev = false, bool $server = true, int $graceDays = 30): LicenceService
    {
        $settings = new LicenceSettings($server ? ServerUrl::parse('https://licences.example.com/v1', false) : null, new GracePolicy($graceDays), $dev, self::SITE, '1.0.0', 'ritmo-revenue', false, [SignedLicenceAnswers::publicKey()]);

        return new LicenceService($this->vault, $this->store, $this->clock, $this->logger, new ProviderChoice($settings, $this->http, $this->clock), $settings);
    }

    /**
     * A "valid" answer signed with the test key, for the request it replies to.
     */
    private static function valid(?string $until = '2027-11-15T00:00:00Z'): Closure
    {
        $clock = self::$now;

        return SignedLicenceAnswers::answer('valid', $until, 'Licence active.', static fn (): string => $clock?->now()->format('Y-m-d\\TH:i:s\\Z') ?? '');
    }

    public function testSavingAKeyActivatesItAndKeepsItOnlyInTheVault(): void
    {
        $this->http->queue(self::valid());
        $service = $this->service();

        $verdict = $service->save('  ' . self::KEY . ' ');

        self::assertSame(LicenceStanding::Active, $verdict->standing);
        self::assertSame(['POST /v1/activate'], $this->http->lines());
        self::assertSame(self::KEY, $this->vault->reveal(LicenceService::VAULT_NAME));
        self::assertTrue($service->keyStatus()->configured);
        $everythingStored = (string) json_encode($this->store->all());
        self::assertStringNotContainsString(self::KEY, $everythingStored, 'the key is encrypted in the vault, never in clear');
        self::assertStringNotContainsString(self::KEY, $this->logger->dump(), 'the key is never logged');
        self::assertSame('Licence active.', $service->record()->message);
    }

    public function testABadKeyIsRefusedBeforeAnythingIsSent(): void
    {
        try {
            $this->service()->save('not a key');
            self::fail('accepted');
        } catch (LicenceRefused $refused) {
            self::assertSame(LicenceRefused::BAD_KEY, $refused->reason);
        }
        self::assertSame([], $this->http->lines());
        self::assertFalse($this->vault->has(LicenceService::VAULT_NAME));
    }

    public function testWithoutAKeyNothingIsAskedAndTheVerdictIsNoLicence(): void
    {
        $service = $this->service();

        self::assertSame(LicenceReason::NO_KEY, $service->check()->reason);
        self::assertNull($service->daily());
        self::assertSame([], $this->http->lines());
    }

    public function testCheckNowHasACooldown(): void
    {
        $this->http->queue(self::valid(), self::valid());
        $service = $this->service();
        $service->save(self::KEY);

        $this->clock->advance(10);
        try {
            $service->check();
            self::fail('a second check within 30 seconds');
        } catch (LicenceRefused $refused) {
            self::assertSame(LicenceRefused::COOLDOWN, $refused->reason);
        }
        $this->clock->advance(20);
        self::assertSame(LicenceStanding::Active, $service->check()->standing);
        self::assertSame(['POST /v1/activate', 'POST /v1/validate'], $this->http->lines());
    }

    public function testTheDailyCheckAsksOnceADayAndKeepsTheLicenceThroughAnOutage(): void
    {
        $service = $this->service();
        $this->http->queue(self::valid(null));
        $service->save(self::KEY);

        $this->clock->advance(3600);
        self::assertNull($service->daily(), 'not due an hour later');

        // The server goes down for a month: grace, then lapsed; it never throws.
        $this->http->queue(new ConnectorException('No response from licences.example.com: timeout.', '', null, true));
        $this->clock->advance(20 * 3600);
        $during = $service->daily();
        self::assertNotNull($during);
        self::assertSame(LicenceStanding::Grace, $during->standing);
        self::assertSame(LicenceReason::UNREACHABLE, $during->reason);
        self::assertTrue($during->allowsWriting());

        for ($day = 0; $day < 28; ++$day) {
            $this->http->queue(new HttpResponse(503));
            $this->clock->advance(86400);
            $service->daily();
        }
        self::assertSame(LicenceStanding::Grace, $service->verdict()->standing, 'day 29 of 30');
        $this->http->queue(new HttpResponse(503));
        $this->clock->advance(27 * 3600);
        $service->daily();
        self::assertSame(LicenceStanding::Lapsed, $service->verdict()->standing, '30 days after the last valid answer');
        self::assertFalse($service->verdict()->allowsWriting());

        // The server is back and the licence is still valid: active again.
        $this->http->queue(self::valid(null));
        $this->clock->advance(6 * 3600);
        self::assertSame(LicenceStanding::Active, $service->daily()?->standing);
        self::assertSame(0, $this->http->pending());
    }

    public function testTheGracePeriodComesFromTheSettings(): void
    {
        $service = $this->service(false, true, 3);
        $this->http->queue(self::valid(null));
        $service->save(self::KEY);
        $this->http->queue(new HttpResponse(503));
        $this->clock->advance(86400);
        $service->check();

        $this->clock->advance(2 * 86400);
        self::assertSame(LicenceStanding::Lapsed, $service->verdict()->standing);
    }

    public function testAnExpiredLicenceWarnsThenLapses(): void
    {
        $service = $this->service();
        $this->http->queue(self::valid('2026-11-20T00:00:00Z'));
        $service->save(self::KEY);

        $this->clock->advance(6 * 86400);
        $this->http->queue(ScriptedHttpClient::json(200, ['status' => 'expired', 'expires_at' => '2026-11-20T00:00:00Z']));
        $verdict = $service->check();
        self::assertSame(LicenceStanding::Grace, $verdict->standing);
        self::assertSame(LicenceReason::EXPIRED, $verdict->reason);
        self::assertSame('2026-12-20', $verdict->graceEndsAt?->format('Y-m-d'));

        $this->clock->advance(30 * 86400);
        self::assertSame(LicenceStanding::Lapsed, $service->verdict()->standing);
    }

    public function testReplacingTheKeyFreesTheOldOneFirst(): void
    {
        $service = $this->service();
        $this->http->queue(self::valid());
        $service->save(self::KEY);
        $this->http->queue(ScriptedHttpClient::json(200, ['status' => 'deactivated']), self::valid());

        $service->save('RR-2026-NEW-KEY-0002');

        self::assertSame(['POST /v1/activate', 'POST /v1/deactivate', 'POST /v1/activate'], $this->http->lines());
        self::assertSame(self::KEY, json_decode((string) $this->http->requests()[1]['body'], true)['licence_key']);
        self::assertSame('RR-2026-NEW-KEY-0002', json_decode((string) $this->http->requests()[2]['body'], true)['licence_key']);
        self::assertSame(LicenceStanding::Active, $service->verdict()->standing);
    }

    public function testSavingTheSameKeyAgainKeepsItsHistory(): void
    {
        $service = $this->service();
        $this->http->queue(self::valid());
        $service->save(self::KEY);
        $firstValid = $service->record()->lastValidAt;
        $this->clock->advance(3600);
        $this->http->queue(new HttpResponse(503));

        $verdict = $service->save(self::KEY);

        self::assertSame(['POST /v1/activate', 'POST /v1/activate'], $this->http->lines(), 'the same key is not freed first');
        self::assertSame(LicenceStanding::Grace, $verdict->standing);
        self::assertEquals($firstValid, $service->record()->lastValidAt);
    }

    public function testRemovingTheKeyFreesItAndForgetsEverything(): void
    {
        $service = $this->service();
        $this->http->queue(self::valid(), ScriptedHttpClient::json(200, ['status' => 'deactivated']));
        $service->save(self::KEY);

        self::assertTrue($service->remove());

        self::assertFalse($this->vault->has(LicenceService::VAULT_NAME));
        self::assertFalse($this->store->has(LicenceService::STATE_KEY));
        self::assertSame(LicenceStanding::Unlicensed, $service->verdict()->standing);
        self::assertSame(LicenceReason::NO_KEY, $service->verdict()->reason);
    }

    public function testRemovingWorksEvenWhenTheServerIsDown(): void
    {
        $service = $this->service();
        $this->http->queue(self::valid(), new ConnectorException('down', '', null, true));
        $service->save(self::KEY);

        self::assertFalse($service->remove());
        self::assertFalse($this->vault->has(LicenceService::VAULT_NAME));
    }

    public function testTheTestProviderOnlyInDevelopmentMode(): void
    {
        $dev = $this->service(true);
        $verdict = $dev->save('TEST-VALID-0001');
        self::assertSame(LicenceStanding::Active, $verdict->standing);
        self::assertSame([], $this->http->lines(), 'the test provider needs no network');

        // The same stored answer on a site where development mode is off counts for nothing.
        self::assertSame(LicenceReason::TEST_PROVIDER, $this->service(false)->verdict()->reason);

        // Outside development mode a test key goes to the real server like any key.
        $this->http->queue(ScriptedHttpClient::json(404, ['status' => 'invalid']), ScriptedHttpClient::json(404, ['status' => 'invalid']));
        $production = $this->service(false)->save('TEST-VALID-0002');
        self::assertSame(LicenceStanding::Unlicensed, $production->standing);
        self::assertSame(['POST /v1/deactivate', 'POST /v1/activate'], $this->http->lines(), 'the earlier key is freed first, both on the real server');
    }

    public function testWithoutAServerAKeyStaysUncheckedAndNothingIsSent(): void
    {
        $verdict = $this->service(false, false)->save(self::KEY);

        self::assertSame(LicenceStanding::Unlicensed, $verdict->standing);
        self::assertSame(LicenceReason::NOT_CHECKED, $verdict->reason);
        self::assertSame([], $this->http->lines());
    }

    public function testAVaultThatCannotStoreRefusesInWords(): void
    {
        $broken = new SodiumVault($this->store, $this->clock, 'too-short');
        $settings = new LicenceSettings(null, new GracePolicy(), false, self::SITE);
        $service = new LicenceService($broken, $this->store, $this->clock, $this->logger, new ProviderChoice($settings, $this->http, $this->clock), $settings);

        try {
            $service->save(self::KEY);
            self::fail('stored');
        } catch (LicenceRefused $refused) {
            self::assertSame(LicenceRefused::VAULT, $refused->reason);
        }
    }

    public function testAProviderThatThrowsCountsAsNotReached(): void
    {
        $this->http->queue(self::valid(null), new \RuntimeException('bug'));
        $service = $this->service();
        $service->save(self::KEY);
        $this->clock->advance(86400);

        self::assertSame(LicenceStanding::Grace, $service->daily()?->standing);
    }
}
