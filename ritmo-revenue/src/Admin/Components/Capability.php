<?php

declare(strict_types=1);

namespace Ritmo\Admin\Components;

use Ritmo\WordPress\Roles;

/**
 * The plugin's capabilities as the screens use them (admin spec 3.1). MANAGE_SAFETY is new in
 * M4: until the integration adds it to Roles (grants version 2), it is declared here.
 */
final class Capability
{
    public const VIEW = Roles::VIEW;
    public const MANAGE_RULES = Roles::MANAGE_RULES;
    public const APPROVE = Roles::APPROVE;
    public const MANAGE_CONNECTIONS = Roles::MANAGE_CONNECTIONS;
    public const MANAGE_SAFETY = 'ritmo_manage_safety';

    public const ALL = [self::VIEW, self::MANAGE_RULES, self::APPROVE, self::MANAGE_CONNECTIONS, self::MANAGE_SAFETY];

    private function __construct()
    {
    }

    public static function can(string $capability): bool
    {
        return current_user_can($capability);
    }
}
