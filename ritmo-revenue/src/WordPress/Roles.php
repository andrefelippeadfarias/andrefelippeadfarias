<?php

declare(strict_types=1);

namespace Ritmo\WordPress;

use Ritmo\Contracts\KeyValueStore;

/**
 * The plugin's capabilities and its "Revenue Manager" role. Administrators get every capability.
 *
 * Grants are versioned: activation and upgrades grant only the capabilities of versions the site
 * has not received yet, so a capability the site took away from the role or from administrators
 * stays away. The role is created once; a site that deleted it does not get it back.
 */
final class Roles
{
    public const VIEW = 'ritmo_view';

    public const MANAGE_RULES = 'ritmo_manage_rules';

    public const APPROVE = 'ritmo_approve';

    public const MANAGE_CONNECTIONS = 'ritmo_manage_connections';

    public const ROLE = 'ritmo_revenue_manager';

    public const CAPABILITIES = [self::VIEW, self::MANAGE_RULES, self::APPROVE, self::MANAGE_CONNECTIONS];

    /** Capabilities by the version of the grants that first gave them; a new capability goes in a new version. */
    public const GRANTS = [1 => self::CAPABILITIES];

    /** Settings key of the last version of the grants applied to the site. */
    public const VERSION_KEY = 'roles_version';

    private function __construct()
    {
    }

    public static function install(KeyValueStore $settings): void
    {
        $done = $settings->get(self::VERSION_KEY, 0);
        $step = self::pending(is_int($done) ? $done : 0, get_role(self::ROLE) !== null);
        if ($step['create']) {
            add_role(self::ROLE, 'Revenue Manager', ['read' => true] + array_fill_keys($step['grant'], true));
        } else {
            $role = get_role(self::ROLE);
            foreach ($step['grant'] as $capability) {
                $role?->add_cap($capability);
            }
        }
        $administrator = get_role('administrator');
        foreach ($step['grant'] as $capability) {
            $administrator?->add_cap($capability);
        }
        $settings->set(self::VERSION_KEY, max(array_keys(self::GRANTS)));
    }

    /**
     * What an installation does on a site that applied the grants up to $done: create the role
     * (only on a site that never had it) and grant the capabilities of the later versions.
     *
     * @return array{create: bool, grant: list<string>}
     */
    public static function pending(int $done, bool $roleExists): array
    {
        $grant = [];
        foreach (self::GRANTS as $version => $capabilities) {
            if ($version > $done) {
                array_push($grant, ...$capabilities);
            }
        }

        return ['create' => !$roleExists && $done === 0, 'grant' => $grant];
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
