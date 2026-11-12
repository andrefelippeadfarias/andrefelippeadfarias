<?php

declare(strict_types=1);

namespace Ritmo\Tests\Unit\Infrastructure\Support;

/**
 * Stands for WP_Error in the tests of the WordPress HTTP client.
 */
final class WpErrorDouble
{
    public function __construct(private readonly string $message)
    {
    }

    public function get_error_message(): string
    {
        return $this->message;
    }
}
