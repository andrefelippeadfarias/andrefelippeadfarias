<?php

declare(strict_types=1);

namespace Ritmo\Domain\Write\State;

/**
 * What a write did to one room night.
 */
enum WriteKind: string
{
    /** Fields written over what the night held (the pricing system merges them into the rule). */
    case Write = 'write';

    /** The date rule deleted: every field of the night is gone. */
    case Delete = 'delete';

    /** A group: the date rule deleted, then written again with only the sent fields. */
    case Rewrite = 'rewrite';
}
