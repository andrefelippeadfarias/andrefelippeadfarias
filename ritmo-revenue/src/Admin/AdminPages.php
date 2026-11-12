<?php

declare(strict_types=1);

namespace Ritmo\Admin;

use Ritmo\Application\ShadowRun\ShadowRunService;
use Ritmo\Connectors\PriceLabs\PriceLabsConnector;
use Ritmo\Domain\Model\Identifier;
use Ritmo\Domain\Model\Property;
use Ritmo\Infrastructure\Lock\DbLock;
use Ritmo\WordPress\ConnectorFactory;
use Ritmo\WordPress\Roles;
use Ritmo\WordPress\Scheduler;
use Ritmo\WordPress\Services;
use Ritmo\WordPress\Uninstaller;

/**
 * The "Ritmo Revenue" menu and its four screens. Each screen checks its capability, gathers
 * its data from the services and hands it to a template; nothing here writes.
 */
final class AdminPages
{
    public const DASHBOARD = 'ritmo-revenue';

    public const CONNECTIONS = 'ritmo-revenue-connections';

    public const SETUP = 'ritmo-revenue-setup';

    public const DIAGNOSTICS = 'ritmo-revenue-diagnostics';

    private function __construct()
    {
    }

    public static function register(): void
    {
        $title = __('Ritmo Revenue', 'ritmo-revenue');
        add_menu_page($title, $title, Roles::VIEW, self::DASHBOARD, [self::class, 'dashboard'], 'dashicons-chart-line', 58);
        add_submenu_page(self::DASHBOARD, __('Dashboard', 'ritmo-revenue'), __('Dashboard', 'ritmo-revenue'), Roles::VIEW, self::DASHBOARD, [self::class, 'dashboard']);
        add_submenu_page(self::DASHBOARD, __('Connections', 'ritmo-revenue'), __('Connections', 'ritmo-revenue'), Roles::MANAGE_CONNECTIONS, self::CONNECTIONS, [self::class, 'connections']);
        add_submenu_page(self::DASHBOARD, __('Setup', 'ritmo-revenue'), __('Setup', 'ritmo-revenue'), Roles::MANAGE_RULES, self::SETUP, [self::class, 'setup']);
        add_submenu_page(self::DASHBOARD, __('Diagnostics', 'ritmo-revenue'), __('Diagnostics', 'ritmo-revenue'), Roles::VIEW, self::DIAGNOSTICS, [self::class, 'diagnostics']);
    }

    /**
     * On every admin screen, to whoever manages the connections: a RITMO_SECRET_KEY the vault
     * cannot use. The plugin keeps working; saved keys wait until the constant is fixed.
     */
    public static function vaultNotice(): void
    {
        $problem = current_user_can(Roles::MANAGE_CONNECTIONS) ? Services::instance()->vault()->problem() : null;
        if ($problem === null) {
            return;
        }
        printf(
            '<div class="notice notice-error" data-ritmo="vault-problem"><p><strong>%s</strong> %s</p></div>',
            esc_html__('Ritmo Revenue:', 'ritmo-revenue'),
            esc_html__('RITMO_SECRET_KEY in wp-config.php is too short (at least 32 characters). Saved keys cannot be used or changed until it is fixed.', 'ritmo-revenue')
        );
    }

    public static function enqueue(string $hook): void
    {
        if (!str_contains($hook, self::DASHBOARD)) {
            return;
        }
        wp_enqueue_style('ritmo-admin', plugins_url('assets/admin.css', RITMO_FILE), [], RITMO_VERSION);
        wp_enqueue_script('ritmo-admin', plugins_url('assets/admin.js', RITMO_FILE), [], RITMO_VERSION, true);
    }

    public static function dashboard(): void
    {
        self::requireCapability(Roles::VIEW);
        $services = Services::instance();
        $properties = $services->properties()->all();
        $selected = self::selectedProperty($properties);

        View::render('admin/dashboard', self::common(self::DASHBOARD) + [
            'properties' => array_map(static fn (Property $p): array => ['id' => $p->id, 'name' => $p->name], $properties),
            'selected' => $selected,
            'board' => $selected === null ? null : $services->dashboard()->build($selected),
            'rest_url' => rest_url('ritmo/v1/'),
            'rest_nonce' => wp_create_nonce('wp_rest'),
            'can_run' => current_user_can(Roles::MANAGE_RULES),
            'switch_engaged' => $services->emergencySwitch()->isEngaged(),
        ]);
    }

    public static function connections(): void
    {
        self::requireCapability(Roles::MANAGE_CONNECTIONS);
        $services = Services::instance();
        $vault = $services->vault();
        $store = $services->store();

        View::render('admin/connections', self::common(self::CONNECTIONS) + [
            'beds24' => [
                'secret' => $vault->status(ConnectorFactory::BEDS24_SECRET),
                'token_kind' => (string) $store->get(ConnectorFactory::BEDS24_TOKEN_KIND, 'refresh'),
                'property_ids' => (string) $store->get(ConnectorFactory::BEDS24_PROPERTY_IDS, ''),
                'last_test' => $store->get('connection_test_beds24'),
            ],
            'pricelabs' => [
                'secret' => $vault->status(ConnectorFactory::PRICELABS_SECRET),
                'pms' => (string) $store->get(ConnectorFactory::PRICELABS_PMS, ConnectorFactory::DEFAULT_PRICELABS_PMS),
                'last_test' => $store->get('connection_test_' . PriceLabsConnector::ID),
            ],
            'calendar_read' => $services->connectors()->readsPricingCalendar(),
        ]);
    }

    public static function setup(): void
    {
        self::requireCapability(Roles::MANAGE_RULES);
        $services = Services::instance();
        $rooms = [];
        foreach ($services->roomTypes()->all() as $room) {
            $rooms[$room->propertyId][] = $room->toArray();
        }

        View::render('admin/setup', self::common(self::SETUP) + [
            'properties' => array_map(static fn (Property $p): array => $p->toArray(), $services->properties()->all()),
            'rooms' => $rooms,
            'timezones' => timezone_identifiers_list(),
            'rules_version' => $services->ruleSets()->currentVersion(),
        ]);
    }

    public static function diagnostics(): void
    {
        self::requireCapability(Roles::VIEW);
        $services = Services::instance();
        $migrator = $services->migrator();
        $locks = $services->locks();
        $switch = $services->emergencySwitch();

        View::render('admin/diagnostics', self::common(self::DIAGNOSTICS) + [
            'versions' => [
                'plugin' => RITMO_VERSION,
                'db_current' => $migrator->currentVersion(),
                'db_latest' => $migrator->latestVersion(),
                'wordpress' => get_bloginfo('version'),
                'php' => PHP_VERSION,
                'sodium' => function_exists('sodium_crypto_secretbox'),
            ],
            'runs' => $services->runs()->latest(null, 10),
            'locks' => self::locks($locks, $services->properties()->all()),
            'cron' => Scheduler::state(),
            'switch' => ['engaged' => $switch->isEngaged(), 'by_constant' => $switch->isEngagedByConstant()],
            'calendar_read' => $services->connectors()->readsPricingCalendar(),
            'vault' => ['protection' => $services->vault()->protection(), 'problem' => $services->vault()->problem()],
            'delete_data' => $services->store()->get(Uninstaller::DELETE_DATA, false) === true,
            'can_manage' => current_user_can(Roles::MANAGE_CONNECTIONS),
            'log' => $services->logger()->recent(20, 'info'),
        ]);
    }

    /**
     * @param list<Property> $properties
     *
     * @return list<array{property: string, holder: array<string, mixed>|null}>
     */
    private static function locks(DbLock $locks, array $properties): array
    {
        return array_map(static fn (Property $p): array => ['property' => $p->id, 'holder' => $locks->holder(ShadowRunService::lockKey($p->id))], $properties);
    }

    /**
     * @param list<Property> $properties
     */
    private static function selectedProperty(array $properties): ?string
    {
        // Reading which property to show is not a change: no nonce is needed for this GET argument.
        $asked = isset($_GET['property']) && is_string($_GET['property']) ? sanitize_text_field(wp_unslash($_GET['property'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if (Identifier::isCode($asked)) {
            foreach ($properties as $property) {
                if ($property->id === $asked) {
                    return $property->id;
                }
            }
        }

        return $properties === [] ? null : $properties[0]->id;
    }

    /**
     * @return array{page: string, notice: array{type: string, message: string, details: list<string>}|null, pages: array<string, string>, post_url: string}
     */
    private static function common(string $page): array
    {
        $pages = [self::DASHBOARD => __('Dashboard', 'ritmo-revenue')];
        if (current_user_can(Roles::MANAGE_CONNECTIONS)) {
            $pages[self::CONNECTIONS] = __('Connections', 'ritmo-revenue');
        }
        if (current_user_can(Roles::MANAGE_RULES)) {
            $pages[self::SETUP] = __('Setup', 'ritmo-revenue');
        }
        $pages[self::DIAGNOSTICS] = __('Diagnostics', 'ritmo-revenue');

        return ['page' => $page, 'notice' => Notice::take(), 'pages' => $pages, 'post_url' => admin_url('admin-post.php')];
    }

    private static function requireCapability(string $capability): void
    {
        if (!current_user_can($capability)) {
            wp_die(esc_html__('You are not allowed to see this page.', 'ritmo-revenue'), '', ['response' => 403]);
        }
    }
}
