<?php

declare(strict_types=1);

namespace Ritmo\Application\Dashboard\Setup;

use InvalidArgumentException;

/**
 * The room step was refused as a whole: each refused row with its number and reason.
 */
final class RoomRowsRefused extends InvalidArgumentException
{
    /**
     * @param list<array{row: int, message: string}> $errors
     */
    public function __construct(public readonly array $errors)
    {
        parent::__construct('Some rooms could not be saved: nothing was saved.');
    }
}
