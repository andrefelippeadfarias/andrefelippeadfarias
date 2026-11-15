<?php

declare(strict_types=1);

namespace Ritmo\Application\Update;

use DateTimeImmutable;
use DateTimeZone;
use Ritmo\Connectors\Licence\ServerUrl;
use Ritmo\Contracts\HttpClient;
use Ritmo\Contracts\KeyValueStore;
use Ritmo\Contracts\Logger;
use Ritmo\Domain\Support\Clock;
use Throwable;

/**
 * The update channel: asks the update server for the signed release metadata (at most every 12
 * hours, every hour after a failure), keeps the envelope as it came and verifies it again every
 * time it is used, and downloads a package only to check it against its release.
 *
 * Nothing downloaded is unpacked, included or run here: WordPress installs the package after
 * download() has accepted it (Infrastructure\Update\UpdateHooks).
 */
final class UpdateChecker
{
    public const CACHE_KEY = 'update_release';

    public const FRESH_SECONDS = 12 * 3600;

    public const RETRY_SECONDS = 3600;

    public const METADATA_TIMEOUT = 15;

    public const PACKAGE_TIMEOUT = 120;

    public function __construct(
        private readonly HttpClient $http,
        private readonly KeyValueStore $store,
        private readonly Clock $clock,
        private readonly Logger $logger,
        private readonly UpdateSettings $settings,
    ) {
    }

    public function settings(): UpdateSettings
    {
        return $this->settings;
    }

    /**
     * The release to offer, asking the server when the kept answer is old (or always with $force).
     * Never throws: a failure is logged and kept as the last problem.
     */
    public function latest(bool $force = false): ?ReleaseManifest
    {
        if (!$this->settings->enabled()) {
            return null;
        }
        $cache = $this->cache();
        $age = $cache['checked_at'] === null ? PHP_INT_MAX : $this->clock->now()->getTimestamp() - $cache['checked_at'];
        $wait = $cache['problem'] === null ? self::FRESH_SECONDS : self::RETRY_SECONDS;
        if ($force || $age < 0 || $age >= $wait) {
            $this->fetch();
        }

        return $this->offer();
    }

    /**
     * The release of the kept envelope, verified again now, without asking the server; null when
     * there is none to offer.
     */
    public function offer(): ?ReleaseManifest
    {
        $envelope = $this->cache()['envelope'];
        if ($envelope === null || !$this->settings->enabled()) {
            return null;
        }
        try {
            return (new ManifestVerifier($this->settings))->verify($envelope);
        } catch (UpdateRefused) {
            return null;
        }
    }

    /**
     * The code of the last failure of the channel (an UpdateRefused reason), or null.
     */
    public function lastProblem(): ?string
    {
        return $this->cache()['problem'];
    }

    /**
     * Downloads the package of the release on offer and returns its bytes once they are verified.
     *
     * @throws UpdateRefused when $package is not the package of the verified release on offer, or
     *                       when the download fails or does not match the release
     */
    public function download(string $package): string
    {
        $manifest = $this->offer();
        if ($manifest === null || !hash_equals($manifest->package, $package)) {
            throw new UpdateRefused(UpdateRefused::UNKNOWN_PACKAGE, 'The package is not the one of a verified release.');
        }
        try {
            $response = $this->http->request('GET', $manifest->package, ['Accept' => 'application/zip'], null, self::PACKAGE_TIMEOUT);
        } catch (Throwable) {
            throw new UpdateRefused(UpdateRefused::UNREACHABLE, 'The package could not be downloaded.');
        }
        if ($response->status() !== 200) {
            throw new UpdateRefused(UpdateRefused::UNREACHABLE, sprintf('The update server answered %d for the package.', $response->status()));
        }
        $bytes = $response->body();
        try {
            PackageVerifier::verify($bytes, $manifest, $this->settings->keys);
        } catch (UpdateRefused $refused) {
            $this->logger->error('A downloaded update package was refused.', ['reason' => $refused->reason, 'version' => $manifest->version]);

            throw $refused;
        }
        $this->logger->info('An update package was verified.', ['version' => $manifest->version, 'bytes' => strlen($bytes)]);

        return $bytes;
    }

    private function fetch(): void
    {
        /** @var ServerUrl $url enabled() checked it */
        $url = $this->settings->metadataUrl;
        $now = $this->clock->now()->getTimestamp();
        $kept = $this->cache()['envelope'];
        try {
            $response = $this->http->request('GET', $url->url, ['Accept' => 'application/json'], null, self::METADATA_TIMEOUT);
        } catch (Throwable) {
            $this->remember($kept, $now, UpdateRefused::UNREACHABLE);
            $this->logger->warning('The update server could not be reached.', ['provider' => 'update']);

            return;
        }
        if ($response->status() !== 200) {
            $this->remember($kept, $now, UpdateRefused::UNREACHABLE);
            $this->logger->warning('The update server did not answer with the release.', ['provider' => 'update', 'http_status' => $response->status()]);

            return;
        }
        $envelope = $response->body();
        try {
            $manifest = (new ManifestVerifier($this->settings))->verify($envelope);
        } catch (UpdateRefused $refused) {
            if ($refused->reason === UpdateRefused::NOT_NEWER) {
                // Nothing newer: the installed version is current. The old offer goes away.
                $this->remember(null, $now, null);

                return;
            }
            // A refused answer never replaces a verified one: the kept envelope stays.
            $this->remember($kept, $now, $refused->reason);
            $this->logger->error('Release metadata was refused.', ['provider' => 'update', 'reason' => $refused->reason]);

            return;
        }
        $this->remember($envelope, $now, null);
        $this->logger->info('A verified update is available.', ['provider' => 'update', 'version' => $manifest->version]);
    }

    private function remember(?string $envelope, int $checkedAt, ?string $problem): void
    {
        $this->store->set(self::CACHE_KEY, [
            'envelope' => $envelope,
            'checked_at' => (new DateTimeImmutable('@' . $checkedAt))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'),
            'problem' => $problem,
        ]);
    }

    /**
     * @return array{envelope: string|null, checked_at: int|null, problem: string|null}
     */
    private function cache(): array
    {
        $data = $this->store->getFresh(self::CACHE_KEY);
        if (!is_array($data)) {
            return ['envelope' => null, 'checked_at' => null, 'problem' => null];
        }
        $checked = is_string($data['checked_at'] ?? null) ? DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $data['checked_at'], new DateTimeZone('UTC')) : false;

        return [
            'envelope' => is_string($data['envelope'] ?? null) ? $data['envelope'] : null,
            'checked_at' => $checked === false ? null : $checked->getTimestamp(),
            'problem' => is_string($data['problem'] ?? null) ? $data['problem'] : null,
        ];
    }
}
