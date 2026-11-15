<?php

declare(strict_types=1);

namespace Ritmo\Connectors\Licence;

use Ritmo\Domain\Licence\LicenceProof;
use SensitiveParameter;
use SodiumException;

/**
 * The signature a licence server puts on a "valid" answer, so that only the plugin's own licence
 * server can make a licence valid (a server that merely answers {"status":"valid"} cannot).
 *
 * The server signs, with its Ed25519 secret key, the lines
 *
 *   ritmo-licence-v1
 *   <sha256 of the licence key, hex>
 *   <site_url as sent>
 *   <status>
 *   <expires_at as answered, or empty>
 *   <checked_at as answered>
 *   <nonce as sent>
 *
 * joined by "\n", and answers the detached signature in base64 as "signature". The nonce is new
 * for every request, so an old answer cannot be replayed (checked_at is signed but informative).
 */
final class LicenceSignature
{
    public const VERSION = 'ritmo-licence-v1';

    private const KEY_BYTES = 32;

    private function __construct()
    {
    }

    public static function message(#[SensitiveParameter] string $licenceKey, string $siteUrl, string $status, string $expiresAt, string $checkedAt, string $nonce): string
    {
        return implode("\n", [self::VERSION, hash('sha256', $licenceKey), $siteUrl, $status, $expiresAt, $checkedAt, $nonce]);
    }

    /**
     * True when $proof is a "valid" answer signed by one of the keys for this licence key and site.
     *
     * @param list<string> $keys raw 32-byte Ed25519 public keys
     */
    public static function verifies(array $keys, LicenceProof $proof, #[SensitiveParameter] string $licenceKey, string $siteUrl): bool
    {
        return self::verify($keys, $proof->signature, self::message($licenceKey, $siteUrl, 'valid', $proof->expiresAt, $proof->checkedAt, $proof->nonce));
    }

    /**
     * True when one of the keys verifies the base64 detached signature of $message.
     *
     * @param list<string> $keys raw 32-byte Ed25519 public keys
     */
    public static function verify(array $keys, mixed $signature, string $message): bool
    {
        if (!is_string($signature) || !function_exists('sodium_crypto_sign_verify_detached')) {
            return false;
        }
        $raw = base64_decode($signature, true);
        if ($raw === false || strlen($raw) !== 64) {
            return false;
        }
        foreach ($keys as $key) {
            try {
                if (strlen($key) === self::KEY_BYTES && sodium_crypto_sign_verify_detached($raw, $message, $key)) {
                    return true;
                }
            } catch (SodiumException) {
                continue;
            }
        }

        return false;
    }

    /**
     * Raw public keys from base64 text (separated by commas or spaces); parts that are not a key
     * are left out.
     *
     * @return list<string>
     */
    public static function keys(string $text): array
    {
        $keys = [];
        foreach (preg_split('/[\s,]+/', trim($text), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $part) {
            $raw = base64_decode($part, true);
            if ($raw !== false && strlen($raw) === self::KEY_BYTES) {
                $keys[] = $raw;
            }
        }

        return array_values(array_unique($keys));
    }
}
