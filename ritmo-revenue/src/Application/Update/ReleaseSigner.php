<?php

declare(strict_types=1);

namespace Ritmo\Application\Update;

use InvalidArgumentException;
use SensitiveParameter;

/**
 * Signs a release for the update channel (bin/sign-release.php): the package with its own
 * signature, then the metadata envelope that ManifestVerifier checks. The private key is given by
 * the caller for one call and never kept, logged or written by this class.
 */
final class ReleaseSigner
{
    private const SEED_BYTES = 32;

    private const SECRET_BYTES = 64;

    private function __construct()
    {
    }

    /**
     * A new key pair, both halves in base64.
     *
     * @return array{public: string, secret: string}
     */
    public static function keypair(): array
    {
        $pair = sodium_crypto_sign_keypair();

        return ['public' => base64_encode(sodium_crypto_sign_publickey($pair)), 'secret' => base64_encode(sodium_crypto_sign_secretkey($pair))];
    }

    /**
     * The raw 64-byte secret key of a text: the base64 of the 64-byte secret key or of its 32-byte seed.
     *
     * @throws InvalidArgumentException when the text is neither
     */
    public static function secretKey(#[SensitiveParameter] string $text): string
    {
        $raw = base64_decode(trim($text), true);
        if ($raw !== false && strlen($raw) === self::SEED_BYTES) {
            return sodium_crypto_sign_secretkey(sodium_crypto_sign_seed_keypair($raw));
        }
        if ($raw === false || strlen($raw) !== self::SECRET_BYTES) {
            throw new InvalidArgumentException('The signing key is the base64 of an Ed25519 secret key (64 bytes) or seed (32 bytes).');
        }

        return $raw;
    }

    /**
     * The base64 public key of a raw secret key.
     */
    public static function publicKey(#[SensitiveParameter] string $secretKey): string
    {
        return base64_encode(sodium_crypto_sign_publickey_from_secretkey($secretKey));
    }

    /**
     * The signed metadata envelope of a release.
     *
     * @param array{slug?: string, version: string, package: string, name?: string, requires?: string, requires_php?: string, tested?: string, released?: string, changelog?: list<string>} $release
     *
     * @throws InvalidArgumentException when the result would not pass ManifestVerifier's own checks of the fields
     */
    public static function sign(#[SensitiveParameter] string $secretKey, string $packageBytes, array $release): string
    {
        $payload = [
            'slug' => $release['slug'] ?? 'ritmo-revenue',
            'version' => $release['version'],
            'package' => $release['package'],
            'sha256' => hash('sha256', $packageBytes),
            'size' => strlen($packageBytes),
            'package_signature' => base64_encode(sodium_crypto_sign_detached($packageBytes, $secretKey)),
        ];
        foreach (['name', 'requires', 'requires_php', 'tested', 'released', 'changelog'] as $optional) {
            if (isset($release[$optional])) {
                $payload[$optional] = $release[$optional];
            }
        }
        $json = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        try {
            ReleaseManifest::fromPayload($json, UpdateSettings::MAX_PACKAGE_BYTES);
        } catch (UpdateRefused $refused) {
            throw new InvalidArgumentException($refused->getMessage());
        }

        return json_encode([
            'format' => ManifestVerifier::FORMAT,
            'payload' => base64_encode($json),
            'signature' => base64_encode(sodium_crypto_sign_detached($json, $secretKey)),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n";
    }
}
