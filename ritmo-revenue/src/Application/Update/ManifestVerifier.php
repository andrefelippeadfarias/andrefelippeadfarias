<?php

declare(strict_types=1);

namespace Ritmo\Application\Update;

use JsonException;
use Ritmo\Connectors\Licence\ServerUrl;

/**
 * Turns the metadata the update server sends into a release that may be offered, or refuses it.
 *
 * The metadata is an envelope:
 *
 *   {"format": "ritmo-update/1", "payload": "<base64 of the release JSON>", "signature": "<base64 Ed25519>"}
 *
 * The signature is checked over the exact payload bytes with the trusted keys before anything of
 * the payload is read. Then the release must be this plugin's, its package on the update
 * server's own scheme, host and port, and newer than the installed version.
 */
final class ManifestVerifier
{
    public const FORMAT = 'ritmo-update/1';

    private const MAX_ENVELOPE = 262144;

    public function __construct(private readonly UpdateSettings $settings)
    {
    }

    /**
     * @throws UpdateRefused
     */
    public function verify(string $envelope): ReleaseManifest
    {
        $settings = $this->settings;
        if (!$settings->enabled()) {
            throw new UpdateRefused(UpdateRefused::NOT_CONFIGURED, 'The update channel has no address or no trusted key.');
        }
        if (strlen($envelope) > self::MAX_ENVELOPE) {
            throw new UpdateRefused(UpdateRefused::MALFORMED, 'The release metadata is too large.');
        }
        try {
            $data = json_decode($envelope, true, 4, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new UpdateRefused(UpdateRefused::MALFORMED, 'The release metadata is not JSON.');
        }
        if (!is_array($data) || ($data['format'] ?? null) !== self::FORMAT || !is_string($data['payload'] ?? null)) {
            throw new UpdateRefused(UpdateRefused::MALFORMED, 'The release metadata is not a signed envelope of the expected format.');
        }
        $payload = base64_decode($data['payload'], true);
        if ($payload === false || $payload === '') {
            throw new UpdateRefused(UpdateRefused::MALFORMED, 'The payload of the release metadata is not base64.');
        }
        if (!is_string($data['signature'] ?? null) || $data['signature'] === '') {
            throw new UpdateRefused(UpdateRefused::UNSIGNED, 'The release metadata is not signed.');
        }
        $signature = base64_decode($data['signature'], true);
        if ($signature === false || !$settings->keys->verify($signature, $payload)) {
            throw new UpdateRefused(UpdateRefused::BAD_SIGNATURE, 'No trusted key verifies the signature of the release metadata.');
        }
        $manifest = ReleaseManifest::fromPayload($payload, $settings->maxPackageBytes);
        if ($manifest->slug !== $settings->slug) {
            throw new UpdateRefused(UpdateRefused::WRONG_SLUG, 'The release is for another plugin.');
        }
        /** @var ServerUrl $metadata enabled() checked it */
        $metadata = $settings->metadataUrl;
        if (!$metadata->sameOrigin($manifest->package, $settings->devMode)) {
            throw new UpdateRefused(UpdateRefused::OTHER_ORIGIN, 'The package is not on the update server.');
        }
        if (!$manifest->isNewerThan($settings->installedVersion)) {
            throw new UpdateRefused(UpdateRefused::NOT_NEWER, 'The release is not newer than the installed version.');
        }

        return $manifest;
    }
}
