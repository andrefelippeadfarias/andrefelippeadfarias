<?php
/**
 * A fake licence server and update server for tests, served by PHP's built-in server on its own:
 *
 *   RITMO_FAKE_LICENCE_STATE=/tmp/licence.json RITMO_FAKE_UPDATE_SECRET_KEY=<base64> \
 *       php -S 127.0.0.1:8092 tests/fake-apis/LicenceUpdateApi.php
 *
 * Environment:
 *   RITMO_FAKE_LICENCE_STATE      file that keeps the licences, the release, the mode and the request log
 *   RITMO_FAKE_UPDATE_SECRET_KEY  base64 Ed25519 secret key the releases are signed with; the test
 *                                 generates the pair itself (no key is kept in the repository)
 *
 * Licence protocol (docs/LICENCE-UPDATE.md): POST /licence/activate | /licence/validate |
 * /licence/deactivate with {"licence_key", "site_url", "product", "plugin_version"}.
 * Update channel: GET /updates/latest.json (the signed envelope of the release), GET
 * /updates/packages/<name>.zip (the package).
 *
 * Control plane (never called by the plugin):
 *   POST /__licence  {"key", "status": valid|expired|invalid, "expires_at": ISO|null, "max_sites": 1}
 *   POST /__mode     {"mode": "up" | "down" (503) | "garbage" (200 with HTML) | "timeout" (sleeps 3 s)}
 *   POST /__release  {"version", "zip_path", "changelog": [...], "tamper": none|unsigned|bad_signature|payload|other_origin|zip|package_signature|wrong_slug}
 *   DELETE /__release   no release published (404)
 *   GET  /__public-key  {"public_key": base64}
 *   GET  /__log         {"requests": [{"method", "path", "status", "licence", "site"}]} (the key itself is never logged)
 *   POST /__reset
 *
 * It answers from local state only and never calls anything.
 */

declare(strict_types=1);

namespace RitmoFakeApis;

final class LicenceUpdateApi
{
    public const PRODUCT = 'ritmo-revenue';

    public function __construct(private readonly string $stateFile, private readonly string $secretKey, private readonly string $origin)
    {
    }

    /**
     * @return array{status: int, headers: array<string, string>, body: string}
     */
    public function handle(string $method, string $path, string $body): array
    {
        $state = $this->load();
        $licenceKey = '';
        $site = '';
        if (str_starts_with($path, '/licence/')) {
            $request = json_decode($body, true);
            $licenceKey = is_array($request) && is_string($request['licence_key'] ?? null) ? $request['licence_key'] : '';
            $site = is_array($request) && is_string($request['site_url'] ?? null) ? $request['site_url'] : '';
        }
        $reply = $this->route($method, $path, $body, $state);
        if (!str_starts_with($path, '/__')) {
            $state['log'][] = ['method' => $method, 'path' => $path, 'status' => $reply['status'], 'licence' => $licenceKey === '' ? '' : substr(hash('sha256', $licenceKey), 0, 12), 'site' => $site];
        }
        $this->save($state);

        return $reply;
    }

    /**
     * @param array<string, mixed> $state
     *
     * @return array{status: int, headers: array<string, string>, body: string}
     */
    private function route(string $method, string $path, string $body, array &$state): array
    {
        if (str_starts_with($path, '/__')) {
            return $this->control($method, $path, $body, $state);
        }
        $mode = $state['mode'];
        if ($mode === 'down') {
            return self::json(503, ['status' => 'error', 'message' => 'Service unavailable']);
        }
        if ($mode === 'garbage') {
            return ['status' => 200, 'headers' => ['Content-Type' => 'text/html'], 'body' => '<html><body>Maintenance</body></html>'];
        }
        if ($mode === 'timeout') {
            sleep(3);
        }
        if ($method === 'POST' && preg_match('#^/licence/(activate|validate|deactivate)$#', $path, $m) === 1) {
            return $this->licence($m[1], $body, $state);
        }
        if ($method === 'GET' && $path === '/updates/latest.json') {
            return $this->metadata($state);
        }
        if ($method === 'GET' && preg_match('#^/updates/packages/[A-Za-z0-9._-]+\.zip$#', $path) === 1) {
            return $this->package($path, $state);
        }

        return self::json(404, ['status' => 'error', 'message' => 'Not found']);
    }

    /**
     * @param array<string, mixed> $state
     *
     * @return array{status: int, headers: array<string, string>, body: string}
     */
    private function licence(string $action, string $body, array &$state): array
    {
        $request = json_decode($body, true);
        if (!is_array($request) || !is_string($request['licence_key'] ?? null) || !is_string($request['site_url'] ?? null) || ($request['product'] ?? null) !== self::PRODUCT) {
            return self::json(400, ['status' => 'error', 'message' => 'Bad request']);
        }
        $key = $request['licence_key'];
        $site = $request['site_url'];
        if (!isset($state['licences'][$key])) {
            return self::json(404, ['status' => 'invalid', 'message' => 'Unknown licence key.']);
        }
        $licence = &$state['licences'][$key];
        if ($action === 'deactivate') {
            $licence['sites'] = array_values(array_diff($licence['sites'], [$site]));

            return self::json(200, ['status' => 'deactivated']);
        }
        if (!in_array($site, $licence['sites'], true)) {
            if ($action === 'validate') {
                return self::json(200, ['status' => 'invalid', 'expires_at' => null, 'message' => 'The licence is not activated on this site.']);
            }
            if (count($licence['sites']) >= $licence['max_sites']) {
                return self::json(403, ['status' => 'invalid', 'message' => 'The licence is in use on another site.']);
            }
            $licence['sites'][] = $site;
        }

        $checkedAt = gmdate('Y-m-d\\TH:i:s\\Z');
        $nonce = is_string($request['nonce'] ?? null) ? $request['nonce'] : '';
        $signed = implode("\n", ['ritmo-licence-v1', hash('sha256', $key), $site, (string) $licence['status'], (string) ($licence['expires_at'] ?? ''), $checkedAt, $nonce]);

        return self::json(200, [
            'status' => $licence['status'],
            'expires_at' => $licence['expires_at'],
            'message' => $licence['status'] === 'valid' ? 'Licence active.' : 'Licence ' . $licence['status'] . '.',
            'checked_at' => $checkedAt,
            'nonce' => $nonce,
            'signature' => base64_encode(sodium_crypto_sign_detached($signed, $this->secretKey)),
        ]);
    }

    /**
     * @param array<string, mixed> $state
     *
     * @return array{status: int, headers: array<string, string>, body: string}
     */
    private function metadata(array $state): array
    {
        $release = $state['release'];
        if ($release === null) {
            return self::json(404, ['status' => 'error', 'message' => 'No release']);
        }
        $zip = (string) file_get_contents($release['zip_path']);
        $tamper = $release['tamper'];
        $origin = $tamper === 'other_origin' ? 'https://downloads.example.org' : $this->origin;
        $signedZip = $tamper === 'package_signature' ? $zip . 'other bytes' : $zip;
        $payload = json_encode([
            'slug' => $tamper === 'wrong_slug' ? 'another-plugin' : self::PRODUCT,
            'version' => $release['version'],
            'package' => $origin . '/updates/packages/' . self::PRODUCT . '-' . $release['version'] . '.zip',
            'sha256' => hash('sha256', $zip),
            'size' => strlen($zip),
            'package_signature' => base64_encode(sodium_crypto_sign_detached($signedZip, $this->secretKey)),
            'name' => 'Ritmo Revenue',
            'requires' => '6.5',
            'requires_php' => '8.1',
            'tested' => '6.8',
            'released' => '2026-11-15',
            'changelog' => $release['changelog'],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $signer = $tamper === 'bad_signature' ? sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair()) : $this->secretKey;
        $signature = $tamper === 'unsigned' ? '' : base64_encode(sodium_crypto_sign_detached($payload, $signer));
        if ($tamper === 'payload') {
            $payload = str_replace('"version":"' . $release['version'] . '"', '"version":"9' . $release['version'] . '"', $payload);
        }

        return self::json(200, ['format' => 'ritmo-update/1', 'payload' => base64_encode($payload), 'signature' => $signature]);
    }

    /**
     * @param array<string, mixed> $state
     *
     * @return array{status: int, headers: array<string, string>, body: string}
     */
    private function package(string $path, array $state): array
    {
        $release = $state['release'];
        if ($release === null || basename($path) !== self::PRODUCT . '-' . $release['version'] . '.zip') {
            return self::json(404, ['status' => 'error', 'message' => 'No such package']);
        }
        $zip = (string) file_get_contents($release['zip_path']);
        if ($release['tamper'] === 'zip') {
            $zip .= "\x00tampered";
        }

        return ['status' => 200, 'headers' => ['Content-Type' => 'application/zip'], 'body' => $zip];
    }

    /**
     * @param array<string, mixed> $state
     *
     * @return array{status: int, headers: array<string, string>, body: string}
     */
    private function control(string $method, string $path, string $body, array &$state): array
    {
        $data = json_decode($body, true);
        $data = is_array($data) ? $data : [];
        switch ($method . ' ' . $path) {
            case 'POST /__licence':
                $state['licences'][(string) $data['key']] = [
                    'status' => (string) ($data['status'] ?? 'valid'),
                    'expires_at' => $data['expires_at'] ?? null,
                    'max_sites' => (int) ($data['max_sites'] ?? 1),
                    'sites' => $state['licences'][(string) $data['key']]['sites'] ?? [],
                ];

                return self::json(200, ['ok' => true]);
            case 'POST /__mode':
                $state['mode'] = in_array($data['mode'] ?? '', ['up', 'down', 'garbage', 'timeout'], true) ? $data['mode'] : 'up';

                return self::json(200, ['ok' => true]);
            case 'POST /__release':
                if (!is_string($data['zip_path'] ?? null) || !is_file($data['zip_path'])) {
                    return self::json(400, ['error' => 'zip_path must be a file']);
                }
                $state['release'] = [
                    'version' => (string) $data['version'],
                    'zip_path' => $data['zip_path'],
                    'changelog' => is_array($data['changelog'] ?? null) ? array_values($data['changelog']) : [],
                    'tamper' => (string) ($data['tamper'] ?? 'none'),
                ];

                return self::json(200, ['ok' => true]);
            case 'DELETE /__release':
                $state['release'] = null;

                return self::json(200, ['ok' => true]);
            case 'GET /__public-key':
                return self::json(200, ['public_key' => base64_encode(sodium_crypto_sign_publickey_from_secretkey($this->secretKey))]);
            case 'GET /__log':
                return self::json(200, ['requests' => $state['log']]);
            case 'POST /__reset':
                $state = self::fresh();

                return self::json(200, ['ok' => true]);
        }

        return self::json(404, ['error' => 'Unknown control route']);
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array{status: int, headers: array<string, string>, body: string}
     */
    private static function json(int $status, array $data): array
    {
        return ['status' => $status, 'headers' => ['Content-Type' => 'application/json'], 'body' => (string) json_encode($data, JSON_UNESCAPED_SLASHES)];
    }

    /**
     * @return array<string, mixed>
     */
    private static function fresh(): array
    {
        return ['licences' => [], 'mode' => 'up', 'release' => null, 'log' => []];
    }

    /**
     * @return array<string, mixed>
     */
    private function load(): array
    {
        $data = is_file($this->stateFile) ? json_decode((string) file_get_contents($this->stateFile), true) : null;

        return is_array($data) ? $data + self::fresh() : self::fresh();
    }

    /**
     * @param array<string, mixed> $state
     */
    private function save(array $state): void
    {
        file_put_contents($this->stateFile, json_encode($state, JSON_UNESCAPED_SLASHES), LOCK_EX);
    }
}

if (PHP_SAPI === 'cli-server') {
    $secret = base64_decode((string) getenv('RITMO_FAKE_UPDATE_SECRET_KEY'), true);
    if ($secret === false || strlen($secret) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
        http_response_code(500);
        echo 'RITMO_FAKE_UPDATE_SECRET_KEY is not a base64 Ed25519 secret key.';

        return;
    }
    $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
    $api = new LicenceUpdateApi(
        getenv('RITMO_FAKE_LICENCE_STATE') ?: sys_get_temp_dir() . '/ritmo-fake-licence.json',
        $secret,
        'http://' . (string) ($_SERVER['HTTP_HOST'] ?? '127.0.0.1')
    );
    $reply = $api->handle(strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')), (string) (parse_url($uri, PHP_URL_PATH) ?: '/'), (string) file_get_contents('php://input'));
    http_response_code($reply['status']);
    foreach ($reply['headers'] as $name => $value) {
        header($name . ': ' . $value);
    }
    echo $reply['body'];
}
