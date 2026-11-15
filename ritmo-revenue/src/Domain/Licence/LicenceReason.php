<?php

declare(strict_types=1);

namespace Ritmo\Domain\Licence;

/**
 * Why a licence stands where it stands (LicenceVerdict::$reason). Codes, translated by the admin.
 */
final class LicenceReason
{
    /** Nothing wrong: the licence is active. */
    public const NONE = 'none';

    /** No licence key is saved. */
    public const NO_KEY = 'no_key';

    /** A key is saved but the provider has not answered yet. */
    public const NOT_CHECKED = 'not_checked';

    /** The key was confirmed by the test provider, which only counts in development mode. */
    public const TEST_PROVIDER = 'test_provider';

    /** The provider could not be reached; the last valid answer is kept for the grace period. */
    public const UNREACHABLE = 'unreachable';

    /** The licence expired. */
    public const EXPIRED = 'expired';

    /** The provider says the key is not valid (unknown, revoked or used on another site). */
    public const INVALID = 'invalid';

    public const ALL = [self::NONE, self::NO_KEY, self::NOT_CHECKED, self::TEST_PROVIDER, self::UNREACHABLE, self::EXPIRED, self::INVALID];

    private function __construct()
    {
    }
}
