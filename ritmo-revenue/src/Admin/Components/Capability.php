<?php

declare(strict_types=1);

namespace Ritmo\Admin\Components;

use Ritmo\WordPress\Roles;

/**
 * The plugin's capabilities as the screens use them (admin spec 3.1), the same as Roles.
 */
final class Capability
{
    public const VIEW = Roles::VIEW;
    public const MANAGE_RULES = Roles::MANAGE_RULES;
    public const APPROVE = Roles::APPROVE;
    public const MANAGE_CONNECTIONS = Roles::MANAGE_CONNECTIONS;
    public const MANAGE_SAFETY = Roles::MANAGE_SAFETY;

    public const ALL = Roles::CAPABILITIES;

    private function __construct()
    {
    }

    public static function can(string $capability): bool
    {
        return current_user_can($capability);
    }
}
