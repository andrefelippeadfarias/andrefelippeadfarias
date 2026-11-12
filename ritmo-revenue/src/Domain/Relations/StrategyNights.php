<?php

declare(strict_types=1);

namespace Ritmo\Domain\Relations;

/**
 * What the coherence stage does on a night on which a room of the relation's group is priced by
 * the sales ladder.
 */
enum StrategyNights: string
{
    case Apply = 'apply';

    /** The ladder already applied the relation in the strategy stage. */
    case Skip = 'skip';

    /** The relation runs only on ladder nights. */
    case Only = 'only';
}
