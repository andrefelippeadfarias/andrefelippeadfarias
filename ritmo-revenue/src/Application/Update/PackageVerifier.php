<?php

declare(strict_types=1);

namespace Ritmo\Application\Update;

/**
 * Checks the bytes of a downloaded package against its verified release: the size, the SHA-256
 * and the Ed25519 signature of the package itself. Only bytes are read: nothing of the package is
 * unpacked, included or run here; WordPress installs it after this check.
 */
final class PackageVerifier
{
    private function __construct()
    {
    }

    /**
     * @throws UpdateRefused (SIZE, HASH, PACKAGE_SIGNATURE)
     */
    public static function verify(string $bytes, ReleaseManifest $manifest, TrustedKeys $keys): void
    {
        if (strlen($bytes) !== $manifest->size) {
            throw new UpdateRefused(UpdateRefused::SIZE, 'The package is not the size the release metadata says.');
        }
        if (!hash_equals($manifest->sha256, hash('sha256', $bytes))) {
            throw new UpdateRefused(UpdateRefused::HASH, 'The package does not have the hash the release metadata says.');
        }
        if (!$keys->verify($manifest->packageSignature, $bytes)) {
            throw new UpdateRefused(UpdateRefused::PACKAGE_SIGNATURE, 'No trusted key verifies the signature of the package.');
        }
    }
}
