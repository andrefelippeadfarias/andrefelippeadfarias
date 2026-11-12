<?php

declare(strict_types=1);

namespace Ritmo\Domain\Relations;

/**
 * Where a relation runs.
 */
enum RelationStage: string
{
    /** Inside the sales ladder, on unrounded amounts, before plan items exist. */
    case Strategy = 'strategy';

    /** After the engine, night by night, on the plan draft. */
    case Coherence = 'coherence';

    /** Daily audit of the current prices over a long horizon. */
    case Audit = 'audit';

    /** Rules of the plan validator. */
    case Validate = 'validate';
}
