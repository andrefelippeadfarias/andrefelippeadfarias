<?php

declare(strict_types=1);

namespace Ritmo\Application\ShadowRun;

use RuntimeException;

/**
 * The validator refused the plan as a whole (not item by item), for example because it was made
 * for a day that is no longer the property's today.
 */
final class PlanRefused extends RuntimeException
{
    /**
     * @param list<string> $codes error codes of the validator
     */
    public static function withCodes(array $codes): self
    {
        return new self(sprintf('The validator refused the whole plan (%s).', implode(', ', $codes)));
    }
}
