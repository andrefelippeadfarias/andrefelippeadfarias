<?php

declare(strict_types=1);

namespace Ritmo\Tests\Fakes;

use Closure;
use Ritmo\Connectors\Licence\LicenceSignature;
use Ritmo\Contracts\HttpResponse;

/**
 * Answers of a licence server signed with a fixed test key pair (never a real one), built from
 * the request so the key, the site and the nonce match what was sent.
 */
final class SignedLicenceAnswers
{
    private function __construct()
    {
    }

    public static function secretKey(): string
    {
        return sodium_crypto_sign_secretkey(sodium_crypto_sign_seed_keypair(str_repeat("\x07", SODIUM_CRYPTO_SIGN_SEEDBYTES)));
    }

    public static function publicKey(): string
    {
        return sodium_crypto_sign_publickey_from_secretkey(self::secretKey());
    }

    /**
     * A signed answer for the request it replies to.
     *
     * @param string|(Closure(): string) $checkedAt the server's time of the check (a Closure reads a moving clock)
     * @param array<string, mixed>       $change    fields of the answer to change after signing (to test tampering)
     */
    public static function answer(string $status = 'valid', ?string $expiresAt = null, string $message = 'Licence active.', string|Closure $checkedAt = '2026-11-15T10:00:00Z', ?string $secretKey = null, array $change = []): Closure
    {
        return static function (string $method, string $url, array $headers, ?string $body) use ($status, $expiresAt, $message, $checkedAt, $secretKey, $change): HttpResponse {
            $request = json_decode((string) $body, true);
            $request = is_array($request) ? $request : [];
            $nonce = (string) ($request['nonce'] ?? '');
            $checkedAt = $checkedAt instanceof Closure ? (string) $checkedAt() : $checkedAt;
            $signed = LicenceSignature::message((string) ($request['licence_key'] ?? ''), (string) ($request['site_url'] ?? ''), $status, $expiresAt ?? '', $checkedAt, $nonce);
            $data = [
                'status' => $status,
                'expires_at' => $expiresAt,
                'message' => $message,
                'checked_at' => $checkedAt,
                'nonce' => $nonce,
                'signature' => base64_encode(sodium_crypto_sign_detached($signed, $secretKey ?? self::secretKey())),
            ];

            return ScriptedHttpClient::json(200, $change + $data);
        };
    }
}
