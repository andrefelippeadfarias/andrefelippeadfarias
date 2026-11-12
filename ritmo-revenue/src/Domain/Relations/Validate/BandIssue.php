<?php

declare(strict_types=1);

namespace Ritmo\Domain\Relations\Validate;

use DateTimeImmutable;

/**
 * An error or a warning of the discount band rule of the validator.
 */
final class BandIssue
{
    public const CEILING_ABOVE_REFERENCE = 'band.ceiling_above_reference';
    public const ABOVE_REFERENCE = 'band.above_reference';
    public const ABOVE_UPPER = 'band.above_upper';
    public const BELOW_BY_SWITCH = 'band.below_by_switch';
    public const BELOW_BY_ORDER = 'band.below_by_order';
    public const BELOW_CONFIRM_AFTER_RECALC = 'band.below_confirm_after_recalc';
    public const BELOW_LOWER = 'band.below_lower';

    /**
     * @param array<string, scalar|null> $args
     */
    public function __construct(
        public readonly string $code,
        public readonly bool $isError,
        public readonly string $relationId,
        public readonly string $room,
        public readonly DateTimeImmutable $night,
        public readonly array $args = [],
    ) {
    }
}
