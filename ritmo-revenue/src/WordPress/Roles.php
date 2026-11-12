<?php

declare(strict_types=1);

namespace Ritmo\WordPress;

/**
 * The plugin's capabilities and its "Revenue Manager" role. Administrators get every capability.
 */
final class Roles
{
    public const VIEW = 'ritmo_view';

    public const MANAGE_RULES = 'ritmo_manage_rules';

    public const APPROVE = 'ritmo_approve';

    public const MANAGE_CONNECTIONS = 'ritmo_manage_connections';

    public const ROLE = 'ritmo_revenue_manager';

    public const CAPABILITIES = [self::VIEW, self::MANAGE_RULES, self::APPROVE, self::MANAGE_CONNECTIONS];

    private function __construct()
    {
    }

    public static function install(): void
    {
        $grants = array_fill_keys(self::CAPABILITIES, true);
        remove_role(self::ROLE);
        add_role(self::ROLE, 'Revenue Manager', ['read' => true] + $grants);

        $administrator = get_role('administrator');
        foreach (self::CAPABILITIES as $capability) {
            $administrator?->add_cap($capability);
        }
    }

    public static function remove(): void
    {
        remove_role(self::ROLE);
        $administrator = get_role('administrator');
        foreach (self::CAPABILITIES as $capability) {
            $administrator?->remove_cap($capability);
        }
    }
}
