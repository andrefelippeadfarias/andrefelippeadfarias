<?php

declare(strict_types=1);

namespace Ritmo\Tests\Integration\Update;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Ritmo\Application\Licence\LicenceService;
use Ritmo\Application\Licence\LicenceSettings;
use Ritmo\Application\Licence\ProviderChoice;
use Ritmo\Application\Update\TrustedKeys;
use Ritmo\Application\Update\UpdateChecker;
use Ritmo\Application\Update\UpdateRefused;
use Ritmo\Application\Update\UpdateSettings;
use Ritmo\Connectors\Licence\HttpLicenceServer;
use Ritmo\Connectors\Licence\ServerUrl;
use Ritmo\Contracts\LicenceState;
use Ritmo\Domain\Licence\GracePolicy;
use Ritmo\Domain\Licence\LicenceReason;
use Ritmo\Domain\Licence\LicenceStanding;
use Ritmo\Infrastructure\Http\CurlHttpClient;
use Ritmo\Infrastructure\Secrets\SodiumVault;
use Ritmo\Infrastructure\Store\ArrayStore;
use Ritmo\Tests\Fakes\MovableClock;
use Ritmo\Tests\Fakes\RecordingLogger;
use ZipArchive;

/**
 * The licence provider and the update channel over real HTTP (cURL) against the local licence
 * and update server, with a key pair generated for this run.
 */
final class LicenceUpdateSimulatorTest extends TestCase
{
    private const KEY = 'RR-SIM-0001-ABCD';

    private const SITE = 'https://inn.example.com';

    private static LicenceUpdateServer $server;

    private static string $zip;

    private MovableClock $clock;

    public static function setUpBeforeClass(): void
    {
        self::$server = LicenceUpdateServer::start();
        self::$zip = (string) tempnam(sys_get_temp_dir(), 'ritmo-release') . '.zip';
        $zip = new ZipArchive();
        $zip->open(self::$zip, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('ritmo-revenue/ritmo-revenue.php', "<?php\n/**\n * Plugin Name: Ritmo Revenue\n * Version: 1.2.0\n */\n");
        $zip->addFromString('ritmo-revenue/readme.txt', 'A release built by the test.');
        $zip->close();
    }

    public static function tearDownAfterClass(): void
    {
        self::$server->stop();
        if (is_file(self::$zip)) {
            unlink(self::$zip);
        }
    }

    protected function setUp(): void
    {
        self::$server->reset();
        $this->clock = new MovableClock('2026-11-15T10:00:00Z');
    }

    private function licenceServer(): HttpLicenceServer
    {
        return new HttpLicenceServer(new CurlHttpClient(), ServerUrl::parse(self::$server->url('/licence'), true), $this->clock, 'ritmo-revenue', '1.1.0');
    }

    private function checker(string $installed = '1.1.0', ?string $publicKey = null): UpdateChecker
    {
        $settings = new UpdateSettings(ServerUrl::parse(self::$server->url('/updates/latest.json'), true), TrustedKeys::parse($publicKey ?? self::$server->publicKey), $installed, 'ritmo-revenue', 'ritmo-revenue/ritmo-revenue.php', true);

        return new UpdateChecker(new CurlHttpClient(), new ArrayStore(), $this->clock, new RecordingLogger(), $settings);
    }

    public function testALicenceIsActivatedValidatedAndFreed(): void
    {
        self::$server->licence(self::KEY, 'valid', '2027-11-15T00:00:00Z');
        $server = $this->licenceServer();

        $activated = $server->activate(self::KEY, self::SITE);
        self::assertSame(LicenceState::VALID, $activated->status);
        self::assertSame('2027-11-15', $activated->expiresAt?->format('Y-m-d'));
        self::assertSame(LicenceState::VALID, $server->check(self::KEY, self::SITE)->status);
        self::assertSame(LicenceState::INVALID, $server->activate(self::KEY, 'https://other-inn.example.com')->status, 'one site per licence');
        self::assertTrue($server->deactivate(self::KEY, self::SITE));
        self::assertSame(LicenceState::VALID, $server->activate(self::KEY, 'https://other-inn.example.com')->status, 'freed, it moves to the other site');
        self::assertSame(LicenceState::INVALID, $server->check('RR-UNKNOWN-0000', self::SITE)->status);

        foreach (self::$server->requests() as $request) {
            self::assertSame('POST', $request['method']);
            self::assertStringNotContainsString(self::KEY, $request['path']);
        }
    }

    public function testAServerDownOrConfusedIsNotReached(): void
    {
        self::$server->licence(self::KEY);
        $server = $this->licenceServer();

        self::$server->mode('down');
        self::assertSame(LicenceState::UNREACHABLE, $server->check(self::KEY, self::SITE)->status);
        self::$server->mode('garbage');
        self::assertSame(LicenceState::UNREACHABLE, $server->check(self::KEY, self::SITE)->status);
        self::$server->mode('up');
        self::assertSame(LicenceState::INVALID, $server->check(self::KEY, self::SITE)->status, 'not activated on this site yet');
    }

    public function testTheLicenceServiceEndToEndKeepsTheLicenceThroughAnOutage(): void
    {
        self::$server->licence(self::KEY, 'valid', '2027-11-15T00:00:00Z');
        $store = new ArrayStore();
        $logger = new RecordingLogger();
        $settings = new LicenceSettings(ServerUrl::parse(self::$server->url('/licence'), true), new GracePolicy(), false, self::SITE, '1.1.0');
        $service = new LicenceService(new SodiumVault($store, $this->clock, str_repeat('s', 40)), $store, $this->clock, $logger, new ProviderChoice($settings, new CurlHttpClient(), $this->clock), $settings);

        self::assertSame(LicenceStanding::Active, $service->save(self::KEY)->standing);
        self::$server->mode('down');
        $this->clock->advance(86400);
        $down = $service->daily();
        self::assertSame(LicenceStanding::Grace, $down?->standing);
        self::assertSame(LicenceReason::UNREACHABLE, $down?->reason);
        self::$server->mode('up');
        self::$server->licence(self::KEY, 'expired', '2026-11-16T00:00:00Z');
        $this->clock->advance(6 * 3600);
        $expired = $service->daily();
        self::assertSame(LicenceStanding::Grace, $expired?->standing);
        self::assertSame(LicenceReason::EXPIRED, $expired?->reason);
        $this->clock->advance(31 * 86400);
        self::assertSame(LicenceStanding::Lapsed, $service->verdict()->standing);
        self::assertTrue($service->remove());

        self::assertStringNotContainsString(self::KEY, $logger->dump());
        self::assertStringNotContainsString(self::KEY, (string) json_encode($store->all()));
        self::assertSame(['POST /licence/activate 200', 'POST /licence/validate 503', 'POST /licence/validate 200', 'POST /licence/deactivate 200'], self::$server->lines());
    }

    public function testASignedReleaseIsOfferedAndItsPackageVerified(): void
    {
        self::$server->release('1.2.0', self::$zip, 'none', ['Licence screen.', '<b>Signed</b> updates.']);
        $checker = $this->checker();

        $release = $checker->latest();
        self::assertNotNull($release);
        self::assertSame('1.2.0', $release->version);
        self::assertSame(self::$server->url('/updates/packages/ritmo-revenue-1.2.0.zip'), $release->package);
        self::assertSame(['Licence screen.', '<b>Signed</b> updates.'], $release->changelog, 'kept as text; escaped where shown');
        self::assertSame((string) file_get_contents(self::$zip), $checker->download($release->package));
        self::assertSame(['GET /updates/latest.json 200', 'GET /updates/packages/ritmo-revenue-1.2.0.zip 200'], self::$server->lines());
    }

    /**
     * @return array<string, array{string, string, bool}>
     */
    public static function tampered(): array
    {
        return [
            'metadata without signature' => ['unsigned', UpdateRefused::UNSIGNED, false],
            'metadata signed by another key' => ['bad_signature', UpdateRefused::BAD_SIGNATURE, false],
            'metadata changed after signing' => ['payload', UpdateRefused::BAD_SIGNATURE, false],
            'package on another server' => ['other_origin', UpdateRefused::OTHER_ORIGIN, false],
            'release of another plugin' => ['wrong_slug', UpdateRefused::WRONG_SLUG, false],
            'package changed on the server' => ['zip', UpdateRefused::SIZE, true],
            'package signature of other bytes' => ['package_signature', UpdateRefused::PACKAGE_SIGNATURE, true],
        ];
    }

    #[DataProvider('tampered')]
    public function testATamperedReleaseIsNeverOfferedOrInstalled(string $tamper, string $reason, bool $metadataPasses): void
    {
        self::$server->release('1.2.0', self::$zip, $tamper);
        $checker = $this->checker();

        $release = $checker->latest();
        if (!$metadataPasses) {
            self::assertNull($release);
            self::assertSame($reason, $checker->lastProblem());
            self::assertSame(['GET /updates/latest.json 200'], self::$server->lines(), 'no package is downloaded');

            return;
        }
        self::assertNotNull($release);
        try {
            $checker->download($release->package);
            self::fail('the package was accepted');
        } catch (UpdateRefused $refused) {
            self::assertSame($reason, $refused->reason);
        }
    }

    public function testAnOlderReleaseIsNotOffered(): void
    {
        self::$server->release('1.0.0', self::$zip);
        $checker = $this->checker('1.1.0');

        self::assertNull($checker->latest());
        self::assertNull($checker->lastProblem(), 'nothing newer is not a problem');
    }

    public function testAnotherPublicKeyTrustsNothingFromThisServer(): void
    {
        self::$server->release('1.2.0', self::$zip);
        $checker = $this->checker('1.1.0', base64_encode(sodium_crypto_sign_publickey(sodium_crypto_sign_keypair())));

        self::assertNull($checker->latest());
        self::assertSame(UpdateRefused::BAD_SIGNATURE, $checker->lastProblem());
    }

    public function testAnUpdateServerDownOffersNothing(): void
    {
        self::$server->release('1.2.0', self::$zip);
        self::$server->mode('down');
        $checker = $this->checker();

        self::assertNull($checker->latest());
        self::assertSame(UpdateRefused::UNREACHABLE, $checker->lastProblem());
    }
}
