<?php

declare(strict_types=1);

namespace Ritmo\Tests\Unit\Application\Update;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Ritmo\Application\Update\PackageVerifier;
use Ritmo\Application\Update\TrustedKeys;
use Ritmo\Application\Update\UpdateChecker;
use Ritmo\Application\Update\UpdateRefused;
use Ritmo\Application\Update\UpdateSettings;
use Ritmo\Contracts\Exception\ConnectorException;
use Ritmo\Contracts\HttpResponse;
use Ritmo\Infrastructure\Store\ArrayStore;
use Ritmo\Tests\Fakes\MovableClock;
use Ritmo\Tests\Fakes\RecordingLogger;
use Ritmo\Tests\Fakes\ScriptedHttpClient;

final class UpdateCheckerTest extends TestCase
{
    private SignedRelease $keys;

    private ScriptedHttpClient $http;

    private ArrayStore $store;

    private MovableClock $clock;

    private RecordingLogger $logger;

    protected function setUp(): void
    {
        $this->keys = new SignedRelease();
        $this->http = new ScriptedHttpClient();
        $this->store = new ArrayStore();
        $this->clock = new MovableClock('2026-11-15T10:00:00Z');
        $this->logger = new RecordingLogger();
    }

    private function checker(?UpdateSettings $settings = null): UpdateChecker
    {
        return new UpdateChecker($this->http, $this->store, $this->clock, $this->logger, $settings ?? $this->keys->settings());
    }

    private static function ok(string $body): HttpResponse
    {
        return new HttpResponse(200, ['Content-Type' => 'application/json'], $body);
    }

    public function testAVerifiedReleaseIsOfferedAndAskedForAtMostEveryTwelveHours(): void
    {
        $zip = SignedRelease::zip();
        $this->http->queue(self::ok($this->keys->envelope($zip)));
        $checker = $this->checker();

        self::assertSame('1.2.0', $checker->latest()?->version);
        $this->clock->advance(11 * 3600);
        self::assertSame('1.2.0', $checker->latest()?->version, 'from the kept envelope');
        self::assertSame(['GET /ritmo-revenue/latest.json'], $this->http->lines());
        self::assertSame('1.2.0', $checker->offer()?->version);
        self::assertNull($checker->lastProblem());
    }

    public function testATamperedAnswerNeverReplacesAVerifiedOne(): void
    {
        $zip = SignedRelease::zip();
        $this->http->queue(self::ok($this->keys->envelope($zip)), self::ok((new SignedRelease())->envelope($zip, ['version' => '9.0.0'])));
        $checker = $this->checker();
        $checker->latest();

        $this->clock->advance(13 * 3600);
        self::assertSame('1.2.0', $checker->latest()?->version);
        self::assertSame(UpdateRefused::BAD_SIGNATURE, $checker->lastProblem());
        self::assertNotEmpty($this->logger->entries('error'));
    }

    public function testAStoredEnvelopeIsVerifiedAgainOnEveryUse(): void
    {
        $this->http->queue(self::ok($this->keys->envelope(SignedRelease::zip())));
        $checker = $this->checker();
        $checker->latest();

        // Someone with access to the database swaps the stored release for one of their own.
        $stored = $this->store->get(UpdateChecker::CACHE_KEY);
        $stored['envelope'] = (new SignedRelease())->envelope(SignedRelease::zip('evil'));
        $this->store->set(UpdateChecker::CACHE_KEY, $stored);

        self::assertNull($checker->offer());
        $this->expectException(UpdateRefused::class);
        $checker->download(SignedRelease::PACKAGE_URL);
    }

    public function testAnUnreachableServerIsRetriedAfterAnHourAndNeverThrows(): void
    {
        $this->http->queue(new ConnectorException('No response from updates.example.com: timeout.', '', null, true), new HttpResponse(503), self::ok($this->keys->envelope(SignedRelease::zip())));
        $checker = $this->checker();

        self::assertNull($checker->latest());
        self::assertSame(UpdateRefused::UNREACHABLE, $checker->lastProblem());
        $this->clock->advance(1800);
        self::assertNull($checker->latest());
        self::assertCount(1, $this->http->lines(), 'not again within the hour');
        $this->clock->advance(1800);
        self::assertNull($checker->latest());
        $this->clock->advance(3600);
        self::assertSame('1.2.0', $checker->latest()?->version);
        self::assertNull($checker->lastProblem());
    }

    public function testWhenTheInstalledVersionIsCurrentTheOfferGoesAway(): void
    {
        $this->http->queue(self::ok($this->keys->envelope(SignedRelease::zip())));
        $this->checker()->latest();

        $this->http->queue(self::ok($this->keys->envelope(SignedRelease::zip())));
        $updated = $this->checker($this->keys->settings('1.2.0'));
        self::assertNull($updated->offer(), 'the kept release is not newer than the new installed version');
        self::assertNull($updated->latest(true));
        self::assertNull($updated->lastProblem());
    }

    public function testThePackageIsDownloadedAndVerifiedBeforeWordPressGetsIt(): void
    {
        $zip = SignedRelease::zip();
        $this->http->queue(self::ok($this->keys->envelope($zip)), new HttpResponse(200, ['Content-Type' => 'application/zip'], $zip));
        $checker = $this->checker();
        $checker->latest();

        self::assertSame($zip, $checker->download(SignedRelease::PACKAGE_URL));
        self::assertSame(['GET /ritmo-revenue/latest.json', 'GET /ritmo-revenue/ritmo-revenue-1.2.0.zip'], $this->http->lines());
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function badPackages(): array
    {
        $zip = SignedRelease::zip();

        return [
            'different bytes, same size' => [substr($zip, 0, -1) . "\x01", UpdateRefused::HASH],
            'longer' => [$zip . 'x', UpdateRefused::SIZE],
            'empty' => ['', UpdateRefused::SIZE],
        ];
    }

    #[DataProvider('badPackages')]
    public function testAPackageThatDoesNotMatchItsReleaseIsRefused(string $served, string $reason): void
    {
        $this->http->queue(self::ok($this->keys->envelope(SignedRelease::zip())), new HttpResponse(200, [], $served));
        $checker = $this->checker();
        $checker->latest();

        try {
            $checker->download(SignedRelease::PACKAGE_URL);
            self::fail('accepted');
        } catch (UpdateRefused $refused) {
            self::assertSame($reason, $refused->reason);
        }
    }

    public function testAPackageWhoseOwnSignatureFailsIsRefusedEvenWithTheRightHash(): void
    {
        $zip = SignedRelease::zip();
        $envelope = $this->keys->handMade([
            'slug' => 'ritmo-revenue',
            'version' => '1.2.0',
            'package' => SignedRelease::PACKAGE_URL,
            'sha256' => hash('sha256', $zip),
            'size' => strlen($zip),
            'package_signature' => base64_encode(sodium_crypto_sign_detached($zip . 'other', $this->keys->secret)),
        ]);
        $this->http->queue(self::ok($envelope), new HttpResponse(200, [], $zip));
        $checker = $this->checker();
        $checker->latest();

        $this->expectExceptionObject(new UpdateRefused(UpdateRefused::PACKAGE_SIGNATURE, 'No trusted key verifies the signature of the package.'));
        $checker->download(SignedRelease::PACKAGE_URL);
    }

    public function testOnlyThePackageOfTheVerifiedReleaseIsDownloaded(): void
    {
        $this->http->queue(self::ok($this->keys->envelope(SignedRelease::zip())));
        $checker = $this->checker();
        $checker->latest();

        foreach (['https://updates.example.com/ritmo-revenue/ritmo-revenue-6.6.6.zip', 'https://downloads.wordpress.org/plugin/ritmo-revenue.zip'] as $package) {
            try {
                $checker->download($package);
                self::fail('downloaded ' . $package);
            } catch (UpdateRefused $refused) {
                self::assertSame(UpdateRefused::UNKNOWN_PACKAGE, $refused->reason);
            }
        }
        self::assertCount(1, $this->http->lines(), 'nothing was downloaded');
    }

    public function testADownloadThatFailsIsRefusedInWords(): void
    {
        $this->http->queue(self::ok($this->keys->envelope(SignedRelease::zip())), new HttpResponse(404), new ConnectorException('timeout', '', null, true));
        $checker = $this->checker();
        $checker->latest();

        foreach ([1, 2] as $attempt) {
            try {
                $checker->download(SignedRelease::PACKAGE_URL);
                self::fail('attempt ' . $attempt);
            } catch (UpdateRefused $refused) {
                self::assertSame(UpdateRefused::UNREACHABLE, $refused->reason);
            }
        }
    }

    public function testAnOffChannelAsksNothing(): void
    {
        $checker = $this->checker(new UpdateSettings(null, TrustedKeys::parse($this->keys->public), '1.1.0'));

        self::assertNull($checker->latest(true));
        self::assertSame([], $this->http->lines());
    }

    public function testThePackageVerifierChecksSizeHashAndSignature(): void
    {
        $zip = SignedRelease::zip();
        $this->http->queue(self::ok($this->keys->envelope($zip)));
        $manifest = $this->checker()->latest();
        self::assertNotNull($manifest);

        PackageVerifier::verify($zip, $manifest, TrustedKeys::parse($this->keys->public));
        $this->expectException(UpdateRefused::class);
        PackageVerifier::verify($zip, $manifest, TrustedKeys::parse((new SignedRelease())->public));
    }
}
