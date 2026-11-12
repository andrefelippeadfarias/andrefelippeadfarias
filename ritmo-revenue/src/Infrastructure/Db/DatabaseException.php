<?php

declare(strict_types=1);

namespace Ritmo\Infrastructure\Db;

use RuntimeException;

/**
 * A statement failed or the database could not be used.
 */
final class DatabaseException extends RuntimeException
{
}
