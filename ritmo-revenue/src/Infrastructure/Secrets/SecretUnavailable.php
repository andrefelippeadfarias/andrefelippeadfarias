<?php

declare(strict_types=1);

namespace Ritmo\Infrastructure\Secrets;

use RuntimeException;

/**
 * A secret is not stored, or cannot be decrypted with the keys at hand. The message names
 * the entry and never carries a secret or a key.
 */
final class SecretUnavailable extends RuntimeException
{
}
