<?php

declare(strict_types=1);

namespace Ritmo\Domain\Relations;

/**
 * A relation broken by the conversion of the floors to whole percents.
 */
final class RecheckFinding
{
    /** The target ended above its high reference. */
    public const INVERSION = 'relation.rounding_inversion';

    /** The target could not be put back below its high reference without going below the low one. */
    public const UNSOLVABLE = 'relation.rounding_unsolvable';

    /** The target ended below what its reference (or set) requires. */
    public const BELOW_REQUIRED = 'relation.rounding_below_required';

    /**
     * @param float    $effective   the target's effective floor before any fix
     * @param float    $bound       the amount it had to respect
     * @param int|null $toPercent   the floor percent after the fix, null when not fixed
     */
    public function __construct(
        public readonly string $code,
        public readonly string $relationId,
        public readonly string $room,
        public readonly float $effective,
        public readonly float $bound,
        public readonly int $fromPercent,
        public readonly ?int $toPercent = null,
    ) {
    }
}
