<?php

declare(strict_types=1);

namespace Ritmo\Application\Write\Quota;

use DomainException;

/**
 * An attempt that cannot be recorded: the same attempt with another outcome, one response id on
 * two listings, an instant too far ahead of the clock or an instant without a timezone.
 */
final class AttemptRefused extends DomainException
{
}
