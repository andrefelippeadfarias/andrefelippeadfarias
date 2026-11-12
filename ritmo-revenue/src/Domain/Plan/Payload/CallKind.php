<?php

declare(strict_types=1);

namespace Ritmo\Domain\Plan\Payload;

/**
 * The calls a payload makes to the pricing system, in the order they are written.
 */
enum CallKind: string
{
    /** Delete the date rules first (a ceiling is freed by deleting and writing again). */
    case Delete = 'delete';
    case Write = 'write';
    case Listing = 'listing';
}
