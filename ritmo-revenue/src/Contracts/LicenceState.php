<?php

declare(strict_types=1);

namespace Ritmo\Contracts;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * What a licence provider answered for a licence key.
 */
final class LicenceState
{
    public const VALID = 'valid';

    public const EXPIRED = 'expired';

    public const INVALID = 'invalid';

    /** The provider could not be reached: the caller keeps the last known state. */
    public const UNREACHABLE = 'unreachable';

    /**
     * @param string                 $status    one of the constants of this class
     * @param DateTimeImmutable|null $expiresAt instant in UTC; null when the licence has no end or it is unknown
     * @param DateTimeImmutable      $checkedAt instant in UTC of this answer
     * @param string                 $message   short text for the licence screen
     *
     * @throws InvalidArgumentException on an unknown status
     */
    public function __construct(
        public readonly string $status,
        public readonly ?DateTimeImmutable $expiresAt,
        public readonly DateTimeImmutable $checkedAt,
        public readonly string $message = '',
    ) {
        if (!in_array($status, [self::VALID, self::EXPIRED, self::INVALID, self::UNREACHABLE], true)) {
            throw new InvalidArgumentException(sprintf('Unknown licence status "%s".', $status));
        }
    }

    public function isValid(): bool
    {
        return $this->status === self::VALID;
    }
}
