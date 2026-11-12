<?php

declare(strict_types=1);

namespace Ritmo\Domain\Relations\Coherence;

use DateTimeImmutable;
use Ritmo\Domain\Relations\RelationType;

/**
 * The outcome of one relation on one night.
 */
final class RelationDecision
{
    public function __construct(
        public readonly string $relationId,
        public readonly RelationType $type,
        public readonly string $target,
        public readonly DateTimeImmutable $night,
        public readonly Outcome $outcome,
    ) {
    }
}
