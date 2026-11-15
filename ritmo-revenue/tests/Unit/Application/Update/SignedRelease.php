<?php

declare(strict_types=1);

namespace Ritmo\Tests\Unit\Application\Update;

use Ritmo\Application\Update\ManifestVerifier;
use Ritmo\Application\Update\ReleaseSigner;
use Ritmo\Application\Update\TrustedKeys;
use Ritmo\Application\Update\UpdateSettings;
use Ritmo\Connectors\Licence\ServerUrl;

/**
 * A key pair made for one test, the settings that trust it and releases signed with it (or
 * broken on purpose). No key is kept anywhere.
 */
final class SignedRelease
{
    public const METADATA_URL = 'https://updates.example.com/ritmo-revenue/latest.json';

    public const PACKAGE_URL = 'https://updates.example.com/ritmo-revenue/ritmo-revenue-1.2.0.zip';

    public readonly string $secret;

    public readonly string $public;

    public function __construct()
    {
        $pair = ReleaseSigner::keypair();
        $this->secret = ReleaseSigner::secretKey($pair['secret']);
        $this->public = $pair['public'];
    }

    public function settings(string $installed = '1.1.0', ?string $metadataUrl = self::METADATA_URL): UpdateSettings
    {
        return new UpdateSettings($metadataUrl === null ? null : ServerUrl::parse($metadataUrl, false), TrustedKeys::parse($this->public), $installed);
    }

    /**
     * @param array<string, mixed> $release
     */
    public function envelope(string $zip, array $release = []): string
    {
        return ReleaseSigner::sign($this->secret, $zip, $release + ['version' => '1.2.0', 'package' => self::PACKAGE_URL, 'changelog' => ['Licence screen.', 'Signed updates.']]);
    }

    /**
     * An envelope whose payload is signed by this key but built by hand (fields the signer would refuse).
     *
     * @param array<string, mixed> $payload
     */
    public function handMade(array $payload): string
    {
        $json = (string) json_encode($payload, JSON_UNESCAPED_SLASHES);

        return (string) json_encode(['format' => ManifestVerifier::FORMAT, 'payload' => base64_encode($json), 'signature' => base64_encode(sodium_crypto_sign_detached($json, $this->secret))]);
    }

    public static function zip(string $content = 'release bytes'): string
    {
        return "PK\x03\x04" . $content . str_repeat("\x00", 64);
    }
}
