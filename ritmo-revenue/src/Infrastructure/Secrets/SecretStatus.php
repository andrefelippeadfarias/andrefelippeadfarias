<?php

declare(strict_types=1);

namespace Ritmo\Infrastructure\Secrets;

use DateTimeImmutable;

/**
 * All the interface may know about a stored secret: whether it is there, its last four
 * characters and when it was saved. Never the secret.
 */
final class SecretStatus
{
    /**
     * @param string $lastFour last four characters of the secret; empty when it is not stored or too short to show any
     */
    public function __construct(
        public readonly bool $configured,
        public readonly string $lastFour = '',
        public readonly ?DateTimeImmutable $updatedAt = null,
    ) {
    }
}
