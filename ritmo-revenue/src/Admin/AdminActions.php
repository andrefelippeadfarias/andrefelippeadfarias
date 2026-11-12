<?php

declare(strict_types=1);

namespace Ritmo\Admin;

use InvalidArgumentException;
use OutOfBoundsException;
use Ritmo\Connectors\Beds24\Beds24Connector;
use Ritmo\Connectors\PriceLabs\PriceLabsConnector;
use Ritmo\Domain\Config\InvalidConfig;
use Ritmo\Domain\Config\ValidationError;
use Ritmo\Infrastructure\Secrets\SecretUnavailable;
use Ritmo\WordPress\ConnectorFactory;
use Ritmo\WordPress\Roles;
use Ritmo\WordPress\Services;
use Ritmo\WordPress\Uninstaller;

/**
 * The form actions of the admin (admin-post.php). Every action checks its capability and its
 * nonce before reading the form, then redirects back with a notice. Secrets go to the vault and
 * are never sent back to the browser.
 */
final class AdminActions
{
    /** Action => [handler method, capability, page to return to]. */
    private const ACTIONS = [
        'ritmo_save_connections' => ['saveConnections', Roles::MANAGE_CONNECTIONS, AdminPages::CONNECTIONS],
        'ritmo_test_connection' => ['testConnection', Roles::MANAGE_CONNECTIONS, AdminPages::CONNECTIONS],
        'ritmo_create_property' => ['createProperty', Roles::MANAGE_RULES, AdminPages::SETUP],
        'ritmo_save_room_type' => ['saveRoomType', Roles::MANAGE_RULES, AdminPages::SETUP],
        'ritmo_import_preset' => ['importPreset', Roles::MANAGE_RULES, AdminPages::SETUP],
        'ritmo_import_samples' => ['importSamples', Roles::MANAGE_RULES, AdminPages::SETUP],
        'ritmo_run_shadow' => ['runShadow', Roles::MANAGE_RULES, AdminPages::DASHBOARD],
        'ritmo_emergency_switch' => ['emergencySwitch', Roles::MANAGE_CONNECTIONS, AdminPages::DIAGNOSTICS],
        'ritmo_save_settings' => ['saveSettings', Roles::MANAGE_CONNECTIONS, AdminPages::DIAGNOSTICS],
    ];

    private const PRESET_MAX_BYTES = 1048576;

    private function __construct()
    {
    }

    public static function register(): void
    {
        foreach (array_keys(self::ACTIONS) as $action) {
            add_action('admin_post_' . $action, static fn () => self::handle($action));
        }
    }

    /**
     * @return list<string> the action names, for the templates and the tests
     */
    public static function names(): array
    {
        return array_keys(self::ACTIONS);
    }

    private static function handle(string $action): void
    {
        [$method, $capability, $page] = self::ACTIONS[$action];
        if (!current_user_can($capability)) {
            wp_die(esc_html__('You are not allowed to do this.', 'ritmo-revenue'), '', ['response' => 403]);
        }
        check_admin_referer($action);

        $args = [];
        try {
            $args = self::$method();
        } catch (InvalidConfig $refused) {
            Notice::flash(Notice::ERROR, $refused->getMessage(), array_map(static fn (ValidationError $e): string => (string) $e, $refused->errors()));
        } catch (InvalidArgumentException | OutOfBoundsException | SecretUnavailable $refused) {
            Notice::flash(Notice::ERROR, $refused->getMessage());
        }

        wp_safe_redirect(add_query_arg(['page' => $page] + $args, admin_url('admin.php')));
        exit;
    }

    private static function saveConnections(): array
    {
        $services = Services::instance();
        $vault = $services->vault();
        $store = $services->store();

        $kind = self::field('beds24_token_kind') === 'long_life' ? 'long_life' : 'refresh';
        $ids = self::field('beds24_property_ids');
        if ($ids !== '' && count(ConnectorFactory::propertyIds($ids)) !== count(array_filter(array_map('trim', explode(',', $ids)), 'strlen'))) {
            throw new InvalidArgumentException('Beds24 property ids are whole numbers separated by commas.');
        }
        $pms = strtolower(self::field('pricelabs_pms'));
        if (preg_match('/^[a-z0-9][a-z0-9_.-]{0,39}$/', $pms) !== 1) {
            throw new InvalidArgumentException('The PMS name is the short name PriceLabs uses for the PMS, such as "beds24".');
        }

        foreach ([ConnectorFactory::BEDS24_SECRET => 'beds24_token', ConnectorFactory::PRICELABS_SECRET => 'pricelabs_api_key'] as $secret => $field) {
            $value = self::secret($field);
            if ($value !== '') {
                if (preg_match('/[\x00-\x20\x7f]/', $value) === 1 || strlen($value) > 2048) {
                    throw new InvalidArgumentException('A key or token cannot contain spaces or control characters.');
                }
                $vault->put($secret, $value);
            } elseif (self::field('forget_' . $field) === '1') {
                $vault->forget($secret);
            }
        }
        $store->set(ConnectorFactory::BEDS24_TOKEN_KIND, $kind);
        $store->set(ConnectorFactory::BEDS24_PROPERTY_IDS, implode(',', ConnectorFactory::propertyIds($ids)));
        $store->set(ConnectorFactory::PRICELABS_PMS, $pms);
        Notice::flash(Notice::SUCCESS, __('Connections saved.', 'ritmo-revenue'));

        return [];
    }

    private static function testConnection(): array
    {
        $provider = self::field('provider') === PriceLabsConnector::ID ? PriceLabsConnector::ID : Beds24Connector::ID;
        $services = Services::instance();
        $status = $services->connectors()->test($provider);
        $services->store()->set('connection_test_' . $provider, [
            'ok' => $status->ok,
            'message' => $status->message,
            'at' => $services->clock()->now()->format(DATE_ATOM),
        ]);
        Notice::flash(
            $status->ok ? Notice::SUCCESS : Notice::ERROR,
            /* translators: 1: Beds24 or PriceLabs, 2: answer of the test */
            sprintf(__('Connection test of %1$s: %2$s', 'ritmo-revenue'), $provider === PriceLabsConnector::ID ? 'PriceLabs' : 'Beds24', $status->message)
        );

        return [];
    }

    private static function createProperty(): array
    {
        $property = Services::instance()->setup()->createProperty([
            'id' => self::field('id'),
            'name' => self::field('name'),
            'timezone' => self::field('timezone'),
            'currency' => self::field('currency'),
            'tier' => self::field('tier'),
        ]);
        /* translators: %s: property name */
        Notice::flash(Notice::SUCCESS, sprintf(__('Property "%s" created in shadow mode.', 'ritmo-revenue'), $property->name));

        return [];
    }

    private static function saveRoomType(): array
    {
        $fields = ['property_id', 'code', 'name', 'units', 'pms_ref', 'pricing_ref', 'base', 'min', 'max', 'reference_min', 'tags'];
        $room = Services::instance()->setup()->saveRoomType(array_combine($fields, array_map(self::field(...), $fields)));
        /* translators: %s: room type code */
        Notice::flash(Notice::SUCCESS, sprintf(__('Room type %s saved.', 'ritmo-revenue'), $room->code));

        return [];
    }

    private static function importPreset(): array
    {
        $json = self::text('preset_json');
        $upload = $_FILES['preset_file'] ?? null; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked in handle()
        if (is_array($upload) && is_string($upload['tmp_name'] ?? null) && $upload['tmp_name'] !== '' && is_uploaded_file($upload['tmp_name'])) {
            if ((int) ($upload['size'] ?? 0) > self::PRESET_MAX_BYTES) {
                throw new InvalidArgumentException('A preset file has at most 1 MB.');
            }
            $json = (string) file_get_contents($upload['tmp_name']);
        }
        if (trim($json) === '') {
            throw new InvalidArgumentException('Paste the preset JSON or choose a preset file.');
        }

        $summary = Services::instance()->setup()->importPreset($json, get_current_user_id());
        Notice::flash(Notice::SUCCESS, sprintf(
            /* translators: 1: rule set version, 2: number of properties, 3: number of room types */
            __('Preset imported: rules version %1$d, %2$d properties and %3$d room types, all in shadow mode.', 'ritmo-revenue'),
            $summary['rules_version'],
            $summary['properties'],
            $summary['room_types']
        ));

        return [];
    }

    private static function importSamples(): array
    {
        $summary = Services::instance()->setup()->importSamples(self::field('property_id'), self::text('samples_csv'));
        Notice::flash(Notice::SUCCESS, sprintf(
            /* translators: 1: number of prices, 2: number of nights, 3: number of market anchors updated */
            __('%1$d prices recorded for %2$d nights; %3$d market anchors updated.', 'ritmo-revenue'),
            $summary['samples'],
            $summary['nights'],
            $summary['anchors']
        ));

        return [];
    }

    /**
     * @return array<string, string>
     */
    private static function runShadow(): array
    {
        $property = self::field('property_id');
        $outcome = Services::instance()->shadowRuns()->run($property);
        Notice::flash(
            in_array($outcome->status, ['completed', 'running'], true) ? Notice::SUCCESS : Notice::ERROR,
            /* translators: 1: status of the run, 2: error or empty */
            trim(sprintf(__('Shadow run: %1$s. %2$s', 'ritmo-revenue'), $outcome->status, (string) $outcome->error))
        );

        return ['property' => $property];
    }

    private static function emergencySwitch(): array
    {
        $switch = Services::instance()->emergencySwitch();
        if (self::field('switch') === 'engage') {
            $switch->engage();
            Notice::flash(Notice::WARNING, __('Emergency switch engaged: no run starts and running ones stop before their next stage.', 'ritmo-revenue'));
        } else {
            $switch->release();
            Notice::flash(Notice::SUCCESS, $switch->isEngaged()
                ? __('The option was released, but the constant RITMO_KILL_SWITCH keeps the switch engaged.', 'ritmo-revenue')
                : __('Emergency switch released.', 'ritmo-revenue'));
        }

        return [];
    }

    private static function saveSettings(): array
    {
        Services::instance()->store()->set(Uninstaller::DELETE_DATA, self::field('delete_data') === '1');
        Notice::flash(Notice::SUCCESS, __('Settings saved.', 'ritmo-revenue'));

        return [];
    }

    private static function field(string $name): string
    {
        // The nonce of every form is checked in handle() before any field is read.
        $value = $_POST[$name] ?? ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing

        return is_string($value) ? trim(sanitize_text_field(wp_unslash($value))) : '';
    }

    /**
     * A multi-line value (JSON, CSV), kept as typed: the services validate it.
     */
    private static function text(string $name): string
    {
        $value = $_POST[$name] ?? ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing

        return is_string($value) ? (string) wp_unslash($value) : '';
    }

    /**
     * A secret, kept exactly as typed apart from surrounding spaces.
     */
    private static function secret(string $name): string
    {
        $value = $_POST[$name] ?? ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing

        return is_string($value) ? trim((string) wp_unslash($value)) : '';
    }
}
