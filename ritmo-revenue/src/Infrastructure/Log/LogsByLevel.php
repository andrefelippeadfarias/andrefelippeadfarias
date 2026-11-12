<?php

declare(strict_types=1);

namespace Ritmo\Infrastructure\Log;

use Ritmo\Contracts\Logger;

/**
 * The four level shortcuts of Contracts\Logger, for classes that implement log().
 */
trait LogsByLevel
{
    public function debug(string $message, array $context = []): void
    {
        $this->log(Logger::DEBUG, $message, $context);
    }

    public function info(string $message, array $context = []): void
    {
        $this->log(Logger::INFO, $message, $context);
    }

    public function warning(string $message, array $context = []): void
    {
        $this->log(Logger::WARNING, $message, $context);
    }

    public function error(string $message, array $context = []): void
    {
        $this->log(Logger::ERROR, $message, $context);
    }
}
