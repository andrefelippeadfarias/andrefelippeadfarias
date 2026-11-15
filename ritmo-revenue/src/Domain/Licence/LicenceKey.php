<?php

declare(strict_types=1);

namespace Ritmo\Domain\Licence;

use InvalidArgumentException;
use LogicException;
use SensitiveParameter;

/**
 * A licence key as the customer typed it. It is kept in the secrets vault and sent only to the
 * licence provider; everything else knows it by its fingerprint.
 */
final class LicenceKey
{
    public const MIN_LENGTH = 8;

    public const MAX_LENGTH = 128;

    /** Keys the test provider answers (LicenceRecord::TEST_PROVIDER), only in development mode. */
    public const TEST_PREFIX = 'TEST-';

    private const PATTERN = '/^[A-Za-z0-9][A-Za-z0-9._:-]*$/';

    private function __construct(#[SensitiveParameter] private readonly string $value)
    {
    }

    /**
     * @throws InvalidArgumentException when the text cannot be a licence key (length, spaces, other characters)
     */
    public static function parse(#[SensitiveParameter] string $typed): self
    {
        $key = trim($typed);
        $length = strlen($key);
        if ($length < self::MIN_LENGTH || $length > self::MAX_LENGTH || preg_match(self::PATTERN, $key) !== 1) {
            throw new InvalidArgumentException(sprintf('A licence key has %d to %d letters, digits, dots, colons, hyphens or underscores.', self::MIN_LENGTH, self::MAX_LENGTH));
        }

        return new self($key);
    }

    /**
     * The key itself, for the vault and the provider only.
     */
    public function value(): string
    {
        return $this->value;
    }

    /**
     * Sixteen hexadecimal characters that identify the key without revealing it.
     */
    public function fingerprint(): string
    {
        return substr(hash('sha256', 'ritmo-licence:' . $this->value), 0, 16);
    }

    public function isTestKey(): bool
    {
        return str_starts_with($this->value, self::TEST_PREFIX);
    }

    /**
     * @return array<string, string>
     */
    public function __debugInfo(): array
    {
        return ['fingerprint' => $this->fingerprint()];
    }

    /**
     * @return array<string, string>
     */
    public function __serialize(): array
    {
        throw new LogicException('A licence key is not serialised.');
    }
}
