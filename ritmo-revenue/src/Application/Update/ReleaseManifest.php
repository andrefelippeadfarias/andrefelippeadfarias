<?php

declare(strict_types=1);

namespace Ritmo\Application\Update;

use JsonException;

/**
 * One release as the signed metadata describes it (the payload of the envelope, see
 * ManifestVerifier and docs/LICENCE-UPDATE.md). Built only from a payload whose signature was
 * verified; every field is checked here, so nothing unexpected reaches WordPress.
 */
final class ReleaseManifest
{
    public const VERSION_PATTERN = '/^\d{1,4}\.\d{1,4}\.\d{1,4}(?:-[0-9A-Za-z.]{1,20})?$/';

    private const SHORT_VERSION = '/^\d{1,2}\.\d{1,2}(?:\.\d{1,2})?$/';

    private const CHANGELOG_LINES = 50;

    private const LINE_MAX = 300;

    /**
     * @param list<string> $changelog plain text lines, newest first
     */
    private function __construct(
        public readonly string $slug,
        public readonly string $version,
        public readonly string $package,
        public readonly string $sha256,
        public readonly int $size,
        public readonly string $packageSignature,
        public readonly string $name,
        public readonly string $requires,
        public readonly string $requiresPhp,
        public readonly string $tested,
        public readonly string $released,
        public readonly array $changelog,
    ) {
    }

    /**
     * @throws UpdateRefused (MALFORMED) when a field is missing or not what it must be
     */
    public static function fromPayload(string $payload, int $maxBytes): self
    {
        try {
            $data = json_decode($payload, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw self::malformed('The release metadata is not JSON.');
        }
        if (!is_array($data)) {
            throw self::malformed('The release metadata is not an object.');
        }
        $slug = self::text($data, 'slug', '/^[a-z0-9][a-z0-9-]{0,63}$/');
        $version = self::text($data, 'version', self::VERSION_PATTERN);
        $package = self::text($data, 'package', '#^https?://[^\s]{1,480}$#');
        $sha256 = strtolower(self::text($data, 'sha256', '/^[0-9a-fA-F]{64}$/'));
        $size = $data['size'] ?? null;
        if (!is_int($size) || $size < 1 || $size > $maxBytes) {
            throw self::malformed('The release metadata has no package size or one over the limit.');
        }
        $signature = base64_decode(self::text($data, 'package_signature', '#^[A-Za-z0-9+/]{86}==$#'), true);
        if ($signature === false || strlen($signature) !== TrustedKeys::SIGNATURE_BYTES) {
            throw self::malformed('The release metadata has no package signature.');
        }

        return new self(
            $slug,
            $version,
            $package,
            $sha256,
            $size,
            $signature,
            self::optional($data, 'name', '/^[\p{L}\p{N} ._()-]{1,80}$/u'),
            self::optional($data, 'requires', self::SHORT_VERSION),
            self::optional($data, 'requires_php', self::SHORT_VERSION),
            self::optional($data, 'tested', self::SHORT_VERSION),
            self::optional($data, 'released', '/^\d{4}-\d{2}-\d{2}(T\d{2}:\d{2}:\d{2}Z)?$/'),
            self::changelog($data['changelog'] ?? []),
        );
    }

    /**
     * True when this release is newer than $installed.
     */
    public function isNewerThan(string $installed): bool
    {
        return version_compare($this->version, $installed, '>');
    }

    /**
     * @param array<mixed> $data
     */
    private static function text(array $data, string $key, string $pattern): string
    {
        $value = $data[$key] ?? null;
        if (!is_string($value) || preg_match($pattern, $value) !== 1) {
            throw self::malformed(sprintf('The release metadata has no valid "%s".', $key));
        }

        return $value;
    }

    /**
     * @param array<mixed> $data
     */
    private static function optional(array $data, string $key, string $pattern): string
    {
        return array_key_exists($key, $data) && $data[$key] !== null ? self::text($data, $key, $pattern) : '';
    }

    /**
     * @return list<string>
     */
    private static function changelog(mixed $lines): array
    {
        if (!is_array($lines) || !array_is_list($lines) || count($lines) > self::CHANGELOG_LINES) {
            throw self::malformed('The changelog of the release metadata is not a short list of lines.');
        }
        $out = [];
        foreach ($lines as $line) {
            if (!is_string($line) || preg_match('//u', $line) !== 1 || mb_strlen($line) > self::LINE_MAX || preg_match('/[\x00-\x1f\x7f]/', $line) === 1) {
                throw self::malformed('A changelog line of the release metadata is not plain text.');
            }
            $out[] = trim($line);
        }

        return array_values(array_filter($out, static fn (string $line): bool => $line !== ''));
    }

    private static function malformed(string $message): UpdateRefused
    {
        return new UpdateRefused(UpdateRefused::MALFORMED, $message);
    }
}
