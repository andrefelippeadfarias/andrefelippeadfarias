<?php

declare(strict_types=1);

namespace Ritmo\Connectors\Licence;

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
 *   {"licence_key": "...", "site_url": "https://...", "product": "ritmo-revenue", "plugin_version": "1.0.0"}
 *
 *   200 {"status": "valid" | "expired" | "invalid", "expires_at": "2027-01-31T00:00:00Z" | null, "message": "..."}
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

    public function __construct(
        private readonly HttpClient $http,
        private readonly ServerUrl $server,
        private readonly Clock $clock,
        private readonly string $product = 'ritmo-revenue',
        private readonly string $pluginVersion = '',
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
        try {
            $response = $this->post($endpoint, $licenceKey, $siteUrl);
        } catch (Throwable) {
            return $this->unreachable('The licence server could not be reached.');
        }

        return $this->answer($response);
    }

    private function post(string $endpoint, #[SensitiveParameter] string $licenceKey, string $siteUrl): HttpResponse
    {
        $body = json_encode([
            'licence_key' => $licenceKey,
            'site_url' => $siteUrl,
            'product' => $this->product,
            'plugin_version' => $this->pluginVersion,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        return $this->http->request('POST', $this->server->endpoint($endpoint), ['Content-Type' => 'application/json', 'Accept' => 'application/json'], $body, self::TIMEOUT);
    }

    private function answer(HttpResponse $response): LicenceState
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

        return new LicenceState((string) $data['status'], $expiresAt, $now, $message);
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
