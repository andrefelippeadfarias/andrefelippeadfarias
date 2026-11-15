<?php

declare(strict_types=1);

namespace Ritmo\Application\Update;

use RuntimeException;

/**
 * An update that is not offered or not installed, with the reason as a code. The message is an
 * English sentence safe to log; it never holds a key or a whole response.
 */
final class UpdateRefused extends RuntimeException
{
    /** The update channel has no address or no trusted public key. */
    public const NOT_CONFIGURED = 'not_configured';

    /** The update server could not be reached or did not answer 200. */
    public const UNREACHABLE = 'unreachable';

    /** The metadata is not a signed envelope of the expected format. */
    public const MALFORMED = 'malformed';

    /** The metadata has no signature. */
    public const UNSIGNED = 'unsigned';

    /** No trusted key verifies the signature of the metadata. */
    public const BAD_SIGNATURE = 'bad_signature';

    /** The release is for another plugin. */
    public const WRONG_SLUG = 'wrong_slug';

    /** The package is not on the update server's own scheme, host and port. */
    public const OTHER_ORIGIN = 'other_origin';

    /** The release is not newer than the installed version. */
    public const NOT_NEWER = 'not_newer';

    /** The package downloaded is not the size the metadata says. */
    public const SIZE = 'size';

    /** The package downloaded does not have the hash the metadata says. */
    public const HASH = 'hash';

    /** No trusted key verifies the signature of the package. */
    public const PACKAGE_SIGNATURE = 'package_signature';

    /** The package asked for is not the one of the verified release. */
    public const UNKNOWN_PACKAGE = 'unknown_package';

    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }
}
