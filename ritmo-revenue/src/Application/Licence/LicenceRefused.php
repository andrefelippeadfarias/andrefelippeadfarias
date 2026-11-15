<?php

declare(strict_types=1);

namespace Ritmo\Application\Licence;

use RuntimeException;

/**
 * A licence action that was not done, with a code the screen translates.
 */
final class LicenceRefused extends RuntimeException
{
    /** The typed text cannot be a licence key. */
    public const BAD_KEY = 'bad_key';

    /** The last check was moments ago. */
    public const COOLDOWN = 'cooldown';

    /** The secrets vault cannot store the key (RITMO_SECRET_KEY problem). */
    public const VAULT = 'vault';

    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }
}
