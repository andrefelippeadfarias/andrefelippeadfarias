<?php

declare(strict_types=1);

namespace Ritmo\Infrastructure\Log;

use InvalidArgumentException;
use Ritmo\Contracts\Logger;

/**
 * The order of the log levels.
 */
final class LogLevel
{
    private const RANK = [Logger::DEBUG => 0, Logger::INFO => 1, Logger::WARNING => 2, Logger::ERROR => 3];

    /**
     * @throws InvalidArgumentException on a level that is not one of the Logger constants
     */
    public static function rank(string $level): int
    {
        return self::RANK[$level] ?? throw new InvalidArgumentException(sprintf('Unknown log level "%s".', $level));
    }

    /**
     * Whether an entry of $level is kept by a log whose minimum is $minimum.
     */
    public static function reaches(string $level, string $minimum): bool
    {
        return self::rank($level) >= self::rank($minimum);
    }
}
