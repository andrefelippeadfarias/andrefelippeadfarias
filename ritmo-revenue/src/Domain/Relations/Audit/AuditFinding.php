<?php

declare(strict_types=1);

namespace Ritmo\Domain\Relations\Audit;

use DateTimeImmutable;
use Ritmo\Domain\Relations\RelationType;

/**
 * The audit of a target on a night: whether its current price breaks the relation, and the floor
 * and ceiling the audit proposes.
 */
final class AuditFinding
{
    /**
     * @param float       $current         the target's current calendar price
     * @param float       $floor           proposed floor, whole currency
     * @param float|null  $ceiling         proposed ceiling, null when none
     * @param float       $compared        between: the cheaper reference; premium: the dearest other room
     * @param float       $ownerFloor      between: the target's rule floor without the lead-time market floor
     * @param string|null $loweringOrderRef between: the order that allows going below the owner's floor
     * @param bool        $out             the current price breaks the relation
     * @param float       $final           between: the current price moved into the proposed bounds when out
     */
    public function __construct(
        public readonly string $relationId,
        public readonly RelationType $type,
        public readonly string $room,
        public readonly DateTimeImmutable $night,
        public readonly float $current,
        public readonly float $floor,
        public readonly ?float $ceiling,
        public readonly float $compared,
        public readonly float $ownerFloor = 0.0,
        public readonly ?string $loweringOrderRef = null,
        public readonly bool $out = true,
        public readonly float $final = 0.0,
    ) {
    }
}
