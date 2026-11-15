<?php

declare(strict_types=1);

namespace Ritmo\Connectors\Licence;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use JsonException;
use Ritmo\Contracts\HttpClient;
use Ritmo\Contracts\HttpResponse;
use Ritmo\Contracts\LicenceState;
use Ritmo\Domain\Support\Clock;
use SensitiveParameter;
use Throwable;

/**
 * A licence server over HTTP, at the address of RITMO_LICENCE_SERVER_URL, speaking a small JSON
 * protocol (docs/LICENCE-UPDATE.md) that a licensing platform or a shop of one's own can offer
 * directly or through a thin adapter:
 *
 *   POST <url>/activate | /validate | /deactivate
 *   {"licence_key": "...", "site_url": "https://...", "product": "ritmo-revenue", "plugin_version": "1.0.0", "nonce": "<32 hex>"}
 *
 *   200 {"status": "valid" | "expired" | "invalid", "expires_at": "2027-01-31T00:00:00Z" | null, "message": "...",
 *        "checked_at": "2026-11-15T10:00:00Z", "nonce": "<as sent>", "signature": "<base64 Ed25519>"}
 *
 * A "valid" answer counts only when it is signed by one of the plugin's licence public keys
 * (LicenceSignature), for this key, this site and this request's nonce; an unsigned or wrongly
 * signed "valid" is treated as a server that was not reached (the site keeps its last answer).
 *
 * A 401, 403, 404 or 410 means invalid; anything else that is not such an answer (no response,
 * a timeout, 429, 5xx, a body that is not that JSON) means the server was not reached, and the
 * site keeps its last answer. The key travels only in the body of a POST, never in a URL or a log.
 */
final class HttpLicenceServer implements LicenceServer
{
    public const ID = 'http';

    public const TIMEOUT = 15;

    private const INVALID_STATUSES = [401, 403, 404, 410];

    /**
     * @param list<string>            $publicKeys raw Ed25519 public keys that sign a "valid" answer
     * @param (Closure(): string)|null $nonce     a new nonce for each request (random by default)
     */
    public function __construct(
        private readonly HttpClient $http,
        private readonly ServerUrl $server,
        private readonly Clock $clock,
        private readonly string $product = 'ritmo-revenue',
        private readonly string $pluginVersion = '',
        private readonly array $publicKeys = [],
        private readonly ?Closure $nonce = null,
    ) {
    }

    public function id(): string
    {
        return self::ID;
    }

    public function check(#[SensitiveParameter] string $licenceKey, string $siteUrl): LicenceState
    {
        return $this->ask('validate', $licenceKey, $siteUrl);
    }

    public function activate(#[SensitiveParameter] string $licenceKey, string $siteUrl): LicenceState
    {
        return $this->ask('activate', $licenceKey, $siteUrl);
    }

    public function deactivate(#[SensitiveParameter] string $licenceKey, string $siteUrl): bool
    {
        try {
            $response = $this->post('deactivate', $licenceKey, $siteUrl);
            if (!$response->isSuccess()) {
                return false;
            }
            $data = json_decode($response->body(), true, 8, JSON_THROW_ON_ERROR);

            return is_array($data) && ($data['status'] ?? null) === 'deactivated';
        } catch (Throwable) {
            return false;
        }
    }

    private function ask(string $endpoint, #[SensitiveParameter] string $licenceKey, string $siteUrl): LicenceState
    {
        $nonce = $this->nonce === null ? bin2hex(random_bytes(16)) : ($this->nonce)();
        try {
            $response = $this->post($endpoint, $licenceKey, $siteUrl, $nonce);
        } catch (Throwable) {
            return $this->unreachable('The licence server could not be reached.');
        }

        return $this->answer($response, $licenceKey, $siteUrl, $nonce);
    }

    private function post(string $endpoint, #[SensitiveParameter] string $licenceKey, string $siteUrl, string $nonce = ''): HttpResponse
    {
        $body = json_encode(array_filter([
            'licence_key' => $licenceKey,
            'site_url' => $siteUrl,
            'product' => $this->product,
            'plugin_version' => $this->pluginVersion,
            'nonce' => $nonce,
        ], static fn (string $value, string $name): bool => $name !== 'nonce' || $value !== '', ARRAY_FILTER_USE_BOTH), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        return $this->http->request('POST', $this->server->endpoint($endpoint), ['Content-Type' => 'application/json', 'Accept' => 'application/json'], $body, self::TIMEOUT);
    }

    private function answer(HttpResponse $response, #[SensitiveParameter] string $licenceKey, string $siteUrl, string $nonce): LicenceState
    {
        $status = $response->status();
        $data = self::json($response->body());
        $now = $this->clock->now();
        $message = is_array($data) && is_string($data['message'] ?? null) ? $data['message'] : '';
        if (in_array($status, self::INVALID_STATUSES, true)) {
            return new LicenceState(LicenceState::INVALID, null, $now, $message);
        }
        if ($status !== 200 || !is_array($data) || !in_array($data['status'] ?? null, [LicenceState::VALID, LicenceState::EXPIRED, LicenceState::INVALID], true)) {
            return $this->unreachable($status >= 500 || $status === 429 ? 'The licence server is busy or down.' : 'The licence server gave an answer that is not understood.');
        }
        $expiresAt = $data['expires_at'] ?? null;
        if ($expiresAt !== null) {
            $expiresAt = self::instant($expiresAt);
            if ($expiresAt === null) {
                return $this->unreachable('The licence server gave an answer that is not understood.');
            }
        }

        if ($data['status'] === LicenceState::VALID && !$this->signed($data, $licenceKey, $siteUrl, $nonce)) {
            return $this->unreachable('The licence server\'s answer is not signed with the plugin\'s licence key, so it was not trusted.');
        }

        return new LicenceState((string) $data['status'], $expiresAt, $now, $message);
    }

    /**
     * @param array<mixed> $data
     */
    private function signed(array $data, #[SensitiveParameter] string $licenceKey, string $siteUrl, string $nonce): bool
    {
        if ($this->publicKeys === [] || ($data['nonce'] ?? null) !== $nonce || !is_string($data['checked_at'] ?? null)) {
            return false;
        }
        if (self::instant($data['checked_at']) === null) {
            return false;
        }
        $expiresAt = is_string($data['expires_at'] ?? null) ? $data['expires_at'] : '';
        $message = LicenceSignature::message($licenceKey, $siteUrl, LicenceState::VALID, $expiresAt, $data['checked_at'], $nonce);

        return LicenceSignature::verify($this->publicKeys, $data['signature'] ?? null, $message);
    }

    private function unreachable(string $message): LicenceState
    {
        return new LicenceState(LicenceState::UNREACHABLE, null, $this->clock->now(), $message);
    }

    /**
     * @return array<mixed>|null
     */
    private static function json(string $body): ?array
    {
        if ($body === '' || strlen($body) > 65536) {
            return null;
        }
        try {
            $data = json_decode($body, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        return is_array($data) ? $data : null;
    }

    /**
     * An instant in ISO 8601 with a zone (Z or an offset), in UTC; null for anything else.
     */
    private static function instant(mixed $text): ?DateTimeImmutable
    {
        if (!is_string($text) || preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d+)?(Z|[+-]\d{2}:\d{2})$/', $text) !== 1) {
            return null;
        }
        $instant = DateTimeImmutable::createFromFormat(str_contains($text, '.') ? 'Y-m-d\TH:i:s.uP' : 'Y-m-d\TH:i:sP', $text);
        if ($instant === false) {
            return null;
        }
        $errors = DateTimeImmutable::getLastErrors();
        if (is_array($errors) && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) {
            return null;
        }

        return $instant->setTimezone(new DateTimeZone('UTC'));
    }
}
