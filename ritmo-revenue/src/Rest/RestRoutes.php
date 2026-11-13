<?php

declare(strict_types=1);

namespace Ritmo\Rest;

use OutOfBoundsException;
use Ritmo\Application\ShadowRun\PlanItemStatus;
use Ritmo\Application\ShadowRun\RunOutcome;
use Ritmo\WordPress\Roles;
use Ritmo\WordPress\Services;
use Throwable;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * The REST API ritmo/v1. Every route needs a capability of the plugin and the "wp_rest" nonce of
 * a logged-in session (header X-WP-Nonce). Only the shadow run changes anything, and only in
 * the plugin's own tables.
 */
final class RestRoutes
{
    public const NAMESPACE = 'ritmo/v1';

    private const PROPERTY = '(?P<id>[A-Za-z0-9][A-Za-z0-9_-]{0,31})';

    private function __construct()
    {
    }

    public static function register(): void
    {
        register_rest_route(self::NAMESPACE, '/status', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [self::class, 'status'],
            'permission_callback' => self::allowed(Roles::VIEW),
        ]);
        register_rest_route(self::NAMESPACE, '/properties/' . self::PROPERTY . '/occupancy', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [self::class, 'occupancy'],
            'permission_callback' => self::allowed(Roles::VIEW),
        ]);
        register_rest_route(self::NAMESPACE, '/properties/' . self::PROPERTY . '/shadow-run', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [self::class, 'shadowRun'],
            'permission_callback' => self::allowed(Roles::MANAGE_RULES),
        ]);
        register_rest_route(self::NAMESPACE, '/runs/(?P<id>\d+)', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [self::class, 'run'],
            'permission_callback' => self::allowed(Roles::VIEW),
        ]);        OperationRoutes::register();
    }

    /**
     * The permission check of a route: the capability and the REST nonce of the session.
     */
    public static function allowed(string $capability): callable
    {
        return static function (WP_REST_Request $request) use ($capability): bool|WP_Error {
            if (!is_user_logged_in() || wp_verify_nonce((string) $request->get_header('X-WP-Nonce'), 'wp_rest') === false) {
                return new WP_Error('ritmo_nonce', __('A valid nonce is required.', 'ritmo-revenue'), ['status' => 401]);
            }

            return current_user_can($capability)
                ? true
                : new WP_Error('ritmo_forbidden', __('You are not allowed to do this.', 'ritmo-revenue'), ['status' => 403]);
        };
    }

    public static function status(): WP_REST_Response
    {
        $services = Services::instance();
        $properties = [];
        foreach ($services->properties()->all() as $property) {
            $last = $services->runs()->latest($property->id, 1)[0] ?? null;
            $properties[] = [
                'id' => $property->id,
                'name' => $property->name,
                'mode' => $property->mode->value,
                'last_run' => $last === null ? null : self::runRow($last),
            ];
        }
        $migrator = $services->migrator();

        return new WP_REST_Response([
            'version' => RITMO_VERSION,
            'db_version' => $migrator->currentVersion(),
            'db_up_to_date' => $migrator->isUpToDate(),
            'emergency_switch' => $services->emergencySwitch()->isEngaged(),
            'pricing_calendar_read' => $services->connectors()->readsPricingCalendar(),
            'properties' => $properties,
        ]);
    }

    public static function occupancy(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        try {
            return new WP_REST_Response(Services::instance()->occupancy()->forProperty((string) $request['id']));
        } catch (OutOfBoundsException) {
            return self::unknownProperty();
        }
    }

    public static function shadowRun(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        try {
            $outcome = Services::instance()->shadowRuns()->run((string) $request['id']);
        } catch (OutOfBoundsException) {
            return self::unknownProperty();
        } catch (Throwable) {
            return new WP_Error('ritmo_error', __('The shadow run could not be started.', 'ritmo-revenue'), ['status' => 500]);
        }
        $status = match ($outcome->status) {
            RunOutcome::BUSY => 409,
            RunOutcome::BLOCKED => 423,
            default => 200,
        };

        return new WP_REST_Response($outcome->toArray(), $status);
    }

    public static function run(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $services = Services::instance();
        $run = $services->runs()->find((int) $request['id']);
        if ($run === null) {
            return new WP_Error('ritmo_unknown_run', __('There is no such run.', 'ritmo-revenue'), ['status' => 404]);
        }
        $nights = [];
        foreach ($services->planItems()->forRun($run['id']) as $item) {
            $nights[$item['status']][$item['room_code'] . '|' . $item['night']->format('Y-m-d')] = true;
        }

        return new WP_REST_Response(self::runRow($run) + [
            'summary' => $run['summary'],
            'room_nights' => array_map('count', $nights),
            'would_write' => count($nights[PlanItemStatus::WOULD_WRITE] ?? []),
        ]);
    }

    /**
     * @param array<string, mixed> $run a row of RunRepository
     *
     * @return array<string, mixed>
     */
    private static function runRow(array $run): array
    {
        return [
            'id' => $run['id'],
            'property_id' => $run['property_id'],
            'kind' => $run['kind'],
            'status' => $run['status'],
            'stage' => $run['stage'],
            'started_at' => $run['started_at']->format(DATE_ATOM),
            'finished_at' => $run['finished_at']?->format(DATE_ATOM),
            'error' => $run['error'],
        ];
    }

    private static function unknownProperty(): WP_Error
    {
        return new WP_Error('ritmo_unknown_property', __('There is no such property.', 'ritmo-revenue'), ['status' => 404]);
    }
}
