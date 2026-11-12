<?php

declare(strict_types=1);

namespace Ritmo\Contracts;

/**
 * Proof that a write to an external system was authorised by the write gate for one validated
 * plan. Every write method of a connector requires one.
 *
 * The constructor is private and there is no factory yet: while the plugin is read-only nobody
 * can obtain a token, so no write can be requested even by mistake. The write gate that issues
 * tokens arrives with the milestone that enables writing.
 */
final class WriteToken
{
    private function __construct()
    {
    }
}
