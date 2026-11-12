<?php

declare(strict_types=1);

namespace Ritmo\Contracts;

/**
 * Structured log of the plugin.
 *
 * Rules for every caller: the message is a fixed English sentence; variable data goes in
 * $context (scalars, null and arrays of those). Never log credentials, authorisation headers,
 * full request or response bodies of bookings, or any guest data.
 */
interface Logger
{
    public const DEBUG = 'debug';

    public const INFO = 'info';

    public const WARNING = 'warning';

    public const ERROR = 'error';

    /**
     * @param string               $level   one of the constants of this interface
     * @param array<string, mixed> $context
     */
    public function log(string $level, string $message, array $context = []): void;

    /**
     * @param array<string, mixed> $context
     */
    public function debug(string $message, array $context = []): void;

    /**
     * @param array<string, mixed> $context
     */
    public function info(string $message, array $context = []): void;

    /**
     * @param array<string, mixed> $context
     */
    public function warning(string $message, array $context = []): void;

    /**
     * @param array<string, mixed> $context
     */
    public function error(string $message, array $context = []): void;
}
