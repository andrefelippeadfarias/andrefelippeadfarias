<?php

declare(strict_types=1);

namespace Ritmo\Admin\Screens;

use Ritmo\Admin\Components\Capability;
use Ritmo\Admin\Components\FormAction;
use Ritmo\Admin\Components\Input;
use Ritmo\Admin\Components\Menu;
use Ritmo\Application\Dashboard\Diagnostics\SupportBundle;
use Ritmo\Application\EmergencySwitch;
use Ritmo\Domain\Model\Property;
use Ritmo\Domain\Model\RoomType;
use Ritmo\Domain\Support\Days;
use Ritmo\WordPress\ConnectorFactory;
use Ritmo\WordPress\Roles;
use Ritmo\WordPress\Scheduler;
use Ritmo\WordPress\Uninstaller;

/**
 * The support file of Settings › Diagnostics (admin spec 5.14): a JSON download of allowlisted
 * facts (SupportBundle), for whoever manages the connections. No secret, booking, user name,
 * e-mail address or alert fact goes in it; nothing is written to disk.
 */
final class DiagnosticsActions
{
    private const SETTING_KEYS = [ConnectorFactory::BEDS24_TOKEN_KIND, ConnectorFactory::BEDS24_PROPERTY_IDS, ConnectorFactory::PRICELABS_PMS, Uninstaller::DELETE_DATA, EmergencySwitch::KEY, Roles::VERSION_KEY, 'db_version'];

    private const LOG_ROWS = 500;

    private function __construct()
    {
    }

    /**
     * @return list<FormAction>
     */
    public static function actions(): array
    {
        return [new FormAction('ritmo_download_support_bundle', Capability::MANAGE_CONNECTIONS, Menu::SETTINGS, self::download(...), download: true)];
    }

    /**
     * @return array<string, string>
     */
    private static function download(Input $form): array
    {
        $bundle = SupportBundle::build(self::facts());
        nocache_headers();
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="ritmo-support-' . gmdate('Ymd-Hi') . '.json"');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: no-store');
        echo (string) wp_json_encode($bundle, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- a JSON download, not HTML

        return [];
    }

    /**
     * @return array<string, mixed>
     */
    private static function facts(): array
    {
        $services = ScreenServices::instance()->services();
        $store = $services->store();
        $migrator = $services->migrator();
        $rules = $services->ruleSets()->current();
        $settings = [];
        foreach (self::SETTING_KEYS as $key) {
            $settings[$key] = $store->get($key);
        }
        foreach (ConnectionActions::PROVIDERS as $provider => $secret) {
            $settings[$provider . '_secret'] = $services->vault()->status($secret)->configured ? 'saved' : '';
        }
        $settings['key_protection'] = $services->vault()->protection();
        $properties = $services->properties()->all();

        return [
            'generated_at' => gmdate(DATE_ATOM),
            'versions' => ['plugin' => RITMO_VERSION, 'wordpress' => get_bloginfo('version'), 'php' => PHP_VERSION, 'database' => $migrator->currentVersion() . ' of ' . $migrator->latestVersion()],
            'environment' => [
                'extensions' => array_values(array_intersect(['sodium', 'mbstring', 'json', 'curl', 'pdo_sqlite', 'mysqli', 'intl'], get_loaded_extensions())),
                'wp_cron' => Scheduler::state(),
                'memory_limit' => (string) ini_get('memory_limit'),
                'max_execution_time' => (int) ini_get('max_execution_time'),
                'timezone' => wp_timezone_string(),
            ],
            'active_plugins' => array_values(array_map('strval', (array) get_option('active_plugins', []))),
            'settings' => $settings,
            'rules' => $rules === null ? [] : ['version' => $services->ruleSets()->currentVersion(), 'hash' => $rules->hash(), 'content' => $rules->toArray()],
            'properties' => array_map(static fn (Property $p): array => ['id' => $p->id, 'timezone' => $p->timezone, 'currency' => $p->currency, 'mode' => $p->mode->value, 'tier' => $p->tier], $properties),
            'room_types' => array_map(static fn (RoomType $r): array => ['property' => $r->propertyId, 'code' => $r->code, 'units' => $r->units, 'pms_ref' => $r->pmsRef, 'pricing_ref' => $r->pricingRef], $services->roomTypes()->all()),
            'schedule' => self::schedule($properties),
            'runs' => array_map(static fn (array $r): array => [
                'id' => $r['id'], 'property' => $r['property_id'], 'kind' => $r['kind'], 'mode' => $r['mode']->value, 'status' => $r['status'],
                'stage' => $r['stage'], 'started_at' => $r['started_at']->format(DATE_ATOM), 'finished_at' => $r['finished_at']?->format(DATE_ATOM),
                'stages' => array_keys($r['summary']),
            ], $services->runs()->latest(null, 20)),
            'alerts' => self::alertCounts(),
            'log' => array_map(static fn (array $l): array => [
                'level' => $l['level'],
                'message' => $services->sanitizer()->message($l['message']),
                'context' => $services->sanitizer()->context($l['context']),
                'at' => $l['created_at']->format(DATE_ATOM),
            ], $services->logger()->recent(self::LOG_ROWS, 'debug')),
            'migrations' => ['current' => $migrator->currentVersion(), 'latest' => $migrator->latestVersion()],
        ];
    }

    /**
     * @param list<Property> $properties
     *
     * @return list<array<string, mixed>>
     */
    private static function schedule(array $properties): array
    {
        $slots = [];
        $since = Days::add(Days::date(gmdate('Y-m-d')), -7);
        foreach ($properties as $property) {
            foreach (ScreenServices::instance()->services()->stack()->slots()->latest($property->id, 100) as $slot) {
                if ($slot->slot->localDate < $since) {
                    continue;
                }
                $slots[] = ['property' => $property->id, 'kind' => $slot->slot->kind->value, 'day' => Days::key($slot->slot->localDate), 'time' => $slot->slot->time->toString(), 'status' => $slot->status->value, 'run' => $slot->runId];
            }
        }

        return $slots;
    }

    /**
     * @return array<string, int> "code/severity" => open alerts
     */
    private static function alertCounts(): array
    {
        $counts = [];
        foreach (ScreenServices::instance()->services()->alerts()->unresolved(null, 1000) as $alert) {
            $key = $alert['code'] . '/' . $alert['severity'];
            $counts[$key] = ($counts[$key] ?? 0) + 1;
        }

        return $counts;
    }
}
