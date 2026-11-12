<?php

declare(strict_types=1);

namespace Ritmo\Infrastructure\Lock;

/**
 * Proof of having taken a lock: only its holder can extend or release it.
 */
final class LockHandle
{
    public function __construct(
        public readonly string $key,
        public readonly string $owner,
    ) {
    }
}
