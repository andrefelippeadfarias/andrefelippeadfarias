<?php

declare(strict_types=1);

namespace Ritmo\Domain\Write\Confirm;

/**
 * What the re-read of a block of PMS writes found.
 */
final class PmsCheckResult
{
    /**
     * @param list<string> $differences one line per room night and field that differs ("ROOM YYYY-MM-DD: field")
     * @param int          $writesWithError ranges with at least one difference
     */
    public function __construct(
        public readonly array $differences,
        public readonly int $writesWithError,
    ) {
    }

    public function isStored(): bool
    {
        return $this->differences === [];
    }
}
