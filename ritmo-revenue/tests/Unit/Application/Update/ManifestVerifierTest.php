<?php

declare(strict_types=1);

namespace Ritmo\Tests\Unit\Application\Update;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Ritmo\Application\Update\ManifestVerifier;
use Ritmo\Application\Update\PackageVerifier;
use Ritmo\Application\Update\ReleaseSigner;
use Ritmo\Application\Update\TrustedKeys;
use Ritmo\Application\Update\UpdateRefused;
use Ritmo\Application\Update\UpdateSettings;

final class ManifestVerifierTest extends TestCase
{
    private SignedRelease $keys;

    protected function setUp(): void
    {
        $this->keys = new SignedRelease();
    }

    private function refusal(string $envelope, ?UpdateSettings $settings = null): string
    {
        try {
            (new ManifestVerifier($settings ?? $this->keys->settings()))->verify($envelope);
        } catch (UpdateRefused $refused) {
            return $refused->reason;
        }
        self::fail('the metadata was accepted');
    }

    public function testASignedNewerReleaseOnTheServerIsAccepted(): void
    {
        $zip = SignedRelease::zip();
        $manifest = (new ManifestVerifier($this->keys->settings()))->verify($this->keys->envelope($zip, ['requires' => '6.5', 'requires_php' => '8.1', 'tested' => '6.8', 'released' => '2026-11-15', 'name' => 'Ritmo Revenue']));

        self::assertSame('1.2.0', $manifest->version);
        self::assertSame(SignedRelease::PACKAGE_URL, $manifest->package);
        self::assertSame(hash('sha256', $zip), $manifest->sha256);
        self::assertSame(strlen($zip), $manifest->size);
        self::assertSame(['Licence screen.', 'Signed updates.'], $manifest->changelog);
        self::assertSame('8.1', $manifest->requiresPhp);
        PackageVerifier::verify($zip, $manifest, TrustedKeys::parse($this->keys->public));
    }

    public function testAnEnvelopeWithoutSignatureIsRefused(): void
    {
        $envelope = json_decode($this->keys->envelope(SignedRelease::zip()), true);
        $envelope['signature'] = '';

        self::assertSame(UpdateRefused::UNSIGNED, $this->refusal((string) json_encode($envelope)));
        unset($envelope['signature']);
        self::assertSame(UpdateRefused::UNSIGNED, $this->refusal((string) json_encode($envelope)));
    }

    public function testAnotherKeysSignatureIsRefused(): void
    {
        $other = new SignedRelease();

        self::assertSame(UpdateRefused::BAD_SIGNATURE, $this->refusal($other->envelope(SignedRelease::zip())));
    }

    public function testAPayloadChangedAfterSigningIsRefused(): void
    {
        $envelope = json_decode($this->keys->envelope(SignedRelease::zip()), true);
        $payload = (string) base64_decode($envelope['payload'], true);
        $envelope['payload'] = base64_encode(str_replace('"1.2.0"', '"9.9.9"', $payload));

        self::assertSame(UpdateRefused::BAD_SIGNATURE, $this->refusal((string) json_encode($envelope)));
    }

    public function testAnOlderOrEqualVersionIsNotOffered(): void
    {
        $zip = SignedRelease::zip();

        self::assertSame(UpdateRefused::NOT_NEWER, $this->refusal($this->keys->envelope($zip, ['version' => '1.0.9']), $this->keys->settings('1.1.0')));
        self::assertSame(UpdateRefused::NOT_NEWER, $this->refusal($this->keys->envelope($zip, ['version' => '1.1.0']), $this->keys->settings('1.1.0')));
        self::assertSame(UpdateRefused::NOT_NEWER, $this->refusal($this->keys->envelope($zip, ['version' => '1.2.0-beta.1']), $this->keys->settings('1.2.0')));
    }

    public function testAPackageOnAnotherOriginIsRefused(): void
    {
        foreach (['https://cdn.example.org/ritmo-revenue-1.2.0.zip', 'http://updates.example.com/ritmo-revenue-1.2.0.zip', 'https://updates.example.com:8443/x.zip', 'https://updates.example.com.evil.example/x.zip'] as $package) {
            self::assertSame(UpdateRefused::OTHER_ORIGIN, $this->refusal($this->keys->envelope(SignedRelease::zip(), ['package' => $package])), $package);
        }
    }

    public function testAReleaseOfAnotherPluginIsRefused(): void
    {
        self::assertSame(UpdateRefused::WRONG_SLUG, $this->refusal($this->keys->envelope(SignedRelease::zip(), ['slug' => 'another-plugin'])));
    }

    public function testMalformedMetadataIsRefusedEvenWhenSigned(): void
    {
        $base = ['slug' => 'ritmo-revenue', 'version' => '1.2.0', 'package' => SignedRelease::PACKAGE_URL, 'sha256' => str_repeat('a', 64), 'size' => 10, 'package_signature' => base64_encode(str_repeat("\x01", 64))];
        $cases = [
            'no version' => array_diff_key($base, ['version' => 1]),
            'odd version' => ['version' => '1.2'] + $base,
            'script in version' => ['version' => '1.2.0<script>'] + $base,
            'short hash' => ['sha256' => 'abc'] + $base,
            'size as text' => ['size' => '10'] + $base,
            'huge size' => ['size' => UpdateSettings::MAX_PACKAGE_BYTES + 1] + $base,
            'bad package signature' => ['package_signature' => 'abc'] + $base,
            'changelog object' => ['changelog' => ['a' => 'b']] + $base,
            'changelog with control characters' => ['changelog' => ["line\x07"]] + $base,
            'requires with letters' => ['requires' => 'latest'] + $base,
        ];
        foreach ($cases as $name => $payload) {
            self::assertSame(UpdateRefused::MALFORMED, $this->refusal($this->keys->handMade($payload)), $name);
        }
        self::assertSame(UpdateRefused::MALFORMED, $this->refusal('not json'));
        self::assertSame(UpdateRefused::MALFORMED, $this->refusal('{"format":"other/1","payload":"e30=","signature":"x"}'));
        self::assertSame(UpdateRefused::MALFORMED, $this->refusal('{"format":"ritmo-update/1","payload":"%%%","signature":"x"}'));
    }

    public function testWithoutAddressOrKeyTheChannelIsOff(): void
    {
        $envelope = $this->keys->envelope(SignedRelease::zip());

        self::assertSame(UpdateRefused::NOT_CONFIGURED, $this->refusal($envelope, $this->keys->settings('1.1.0', null)));
        self::assertSame(UpdateRefused::NOT_CONFIGURED, $this->refusal($envelope, new UpdateSettings($this->keys->settings()->metadataUrl, TrustedKeys::parse(''), '1.1.0')));
    }

    public function testAnyTrustedKeyVerifiesSoAKeyCanBeRotated(): void
    {
        $old = new SignedRelease();
        $keys = TrustedKeys::parse($old->public . ', ' . $this->keys->public);
        $settings = new UpdateSettings($this->keys->settings()->metadataUrl, $keys, '1.1.0');

        self::assertSame('1.2.0', (new ManifestVerifier($settings))->verify($this->keys->envelope(SignedRelease::zip()))->version);
        self::assertSame('1.2.0', (new ManifestVerifier($settings))->verify($old->envelope(SignedRelease::zip()))->version);
        self::assertSame(2, $keys->count());
        self::assertCount(2, $keys->ids());
    }

    public function testKeysAreReadStrictly(): void
    {
        self::assertTrue(TrustedKeys::parse('  ')->isEmpty());
        $this->expectException(InvalidArgumentException::class);
        TrustedKeys::parse(base64_encode(str_repeat('k', 31)));
    }

    public function testTheSignerRefusesWhatTheVerifierWouldRefuse(): void
    {
        $this->expectException(InvalidArgumentException::class);
        ReleaseSigner::sign($this->keys->secret, SignedRelease::zip(), ['version' => 'next', 'package' => SignedRelease::PACKAGE_URL]);
    }

    public function testTheSignerTakesASeedOrASecretKeyAndNothingElse(): void
    {
        $seed = random_bytes(32);
        $fromSeed = ReleaseSigner::secretKey(base64_encode($seed));
        self::assertSame(64, strlen($fromSeed));
        self::assertSame($fromSeed, ReleaseSigner::secretKey(base64_encode($fromSeed)));
        self::assertSame(base64_encode(sodium_crypto_sign_publickey_from_secretkey($fromSeed)), ReleaseSigner::publicKey($fromSeed));
        $this->expectException(InvalidArgumentException::class);
        ReleaseSigner::secretKey('not a key');
    }
}
