<?php

declare(strict_types=1);

namespace Ritmo\WordPress;

use Ritmo\Contracts\KeyValueStore;

/**
 * The plugin's capabilities and its two roles (admin spec 3): "Revenue Manager" (every capability
 * but the safety one) and "Revenue Viewer" (read only).
 *
 * Grants are versioned: activation and upgrades apply only the versions the site has not received
 * yet, so a capability the site took away from a role or from administrators stays away, and a
 * role the site deleted is not created again.
 *
 * - Version 1: the manager role is created with the four capabilities of M1-M3; administrators
 *   get the same four.
 * - Version 2: administrators get ritmo_manage_safety (the manager role does not: a site grants
 *   it by hand, decision D2); the viewer role is created with ritmo_view.
 *
 * Deactivation keeps roles and capabilities (a deactivate-activate cycle would otherwise lose the
 * site's own changes); uninstall with "delete data" removes both roles and the five capabilities.
 */
final class Roles
{
    public const VIEW = 'ritmo_view';

    public const MANAGE_RULES = 'ritmo_manage_rules';

    public const APPROVE = 'ritmo_approve';

    public const MANAGE_CONNECTIONS = 'ritmo_manage_connections';

    public const MANAGE_SAFETY = 'ritmo_manage_safety';

    public const ROLE = 'ritmo_revenue_manager';

    public const VIEWER_ROLE = 'ritmo_revenue_viewer';

    public const CAPABILITIES = [self::VIEW, self::MANAGE_RULES, self::APPROVE, self::MANAGE_CONNECTIONS, self::MANAGE_SAFETY];

    /**
     * Per version of the grants: what the manager role, the administrators and the viewer role
     * receive. A new capability goes in a new version.
     *
     * @var array<int, array{manager: list<string>, administrator: list<string>, viewer: list<string>}>
     */
    public const GRANTS = [
        1 => [
            'manager' => [self::VIEW, self::MANAGE_RULES, self::APPROVE, self::MANAGE_CONNECTIONS],
            'administrator' => [self::VIEW, self::MANAGE_RULES, self::APPROVE, self::MANAGE_CONNECTIONS],
            'viewer' => [],
        ],
        2 => [
            'manager' => [],
            'administrator' => [self::MANAGE_SAFETY],
            'viewer' => [self::VIEW],
        ],
    ];

    /** Settings key of the last version of the grants applied to the site. */
    public const VERSION_KEY = 'roles_version';

    private function __construct()
    {
    }

    public static function install(KeyValueStore $settings): void
    {
        $done = $settings->get(self::VERSION_KEY, 0);
        $step = self::pending(is_int($done) ? $done : 0, get_role(self::ROLE) !== null, get_role(self::VIEWER_ROLE) !== null);
        self::apply(self::ROLE, 'Revenue Manager', $step['create'], $step['grant']);
        self::apply(self::VIEWER_ROLE, 'Revenue Viewer', $step['create_viewer'], $step['viewer']);
        $administrator = get_role('administrator');
        foreach ($step['administrator'] as $capability) {
            $administrator?->add_cap($capability);
        }
        $settings->set(self::VERSION_KEY, max(array_keys(self::GRANTS)));
    }

    /**
     * What an installation does on a site that applied the grants up to $done: create each role
     * only in the version that introduced it (and only when the site does not have it), and grant
     * the capabilities of the later versions.
     *
     * @return array{create: bool, grant: list<string>, administrator: list<string>, create_viewer: bool, viewer: list<string>}
     */
    public static function pending(int $done, bool $managerExists, bool $viewerExists = false): array
    {
        $step = ['create' => !$managerExists && $done < 1, 'grant' => [], 'administrator' => [], 'create_viewer' => !$viewerExists && $done < 2, 'viewer' => []];
        foreach (self::GRANTS as $version => $grants) {
            if ($version <= $done) {
                continue;
            }
            array_push($step['grant'], ...$grants['manager']);
            array_push($step['administrator'], ...$grants['administrator']);
            array_push($step['viewer'], ...$grants['viewer']);
        }

        return $step;
    }

    /**
     * Who may move the emergency switch which way (decision D23): whoever approves or manages the
     * connections engages it; only whoever manages the connections releases it.
     */
    public static function maySwitchEmergency(bool $engage): bool
    {
        return $engage
            ? current_user_can(self::APPROVE) || current_user_can(self::MANAGE_CONNECTIONS)
            : current_user_can(self::MANAGE_CONNECTIONS);
    }

    public static function remove(): void
    {
        remove_role(self::ROLE);
        remove_role(self::VIEWER_ROLE);
        $administrator = get_role('administrator');
        foreach (self::CAPABILITIES as $capability) {
            $administrator?->remove_cap($capability);
        }
    }

    /**
     * @param list<string> $capabilities
     */
    private static function apply(string $role, string $name, bool $create, array $capabilities): void
    {
        if ($create) {
            add_role($role, $name, ['read' => true] + array_fill_keys($capabilities, true));

            return;
        }
        $existing = get_role($role);
        foreach ($capabilities as $capability) {
            $existing?->add_cap($capability);
        }
    }
}
