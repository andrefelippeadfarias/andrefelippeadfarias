<?php

declare(strict_types=1);

namespace Ritmo\Admin\Ai;

use Ritmo\WordPress\Roles;

/**
 * The capability of the assistant: asking it to word a report, explain an alert or draft a
 * request. Granted to administrators and to the revenue manager role (Roles, version 3 of the
 * grants). Saving its key and settings needs ritmo_manage_connections; opening a draft in an
 * editor needs ritmo_manage_rules too.
 */
final class AiCapability
{
    public const USE_AI = Roles::USE_AI;

    private function __construct()
    {
    }

    public static function can(): bool
    {
        return current_user_can(self::USE_AI);
    }
}
