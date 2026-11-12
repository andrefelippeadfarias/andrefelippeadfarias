<?php

declare(strict_types=1);

namespace Ritmo\Domain\Relations\Coherence;

use DateTimeImmutable;
use Ritmo\Domain\Relations\Relation;

/**
 * How one relation type acts on one night of the coherence stage.
 */
interface CoherenceRule
{
    public function evaluate(Relation $relation, DateTimeImmutable $night): Outcome;
}
