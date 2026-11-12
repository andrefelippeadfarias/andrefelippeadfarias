<?php

declare(strict_types=1);

namespace Ritmo\Infrastructure\Secrets;

use DateTimeImmutable;

/**
 * All the interface may know about a stored secret: whether it is there and when it was saved.
 * Nothing of the secret itself, not even some of its characters.
 */
final class SecretStatus
{
    public function __construct(
        public readonly bool $configured,
        public readonly ?DateTimeImmutable $updatedAt = null,
    ) {
    }
}
