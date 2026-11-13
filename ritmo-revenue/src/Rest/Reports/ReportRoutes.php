<?php

declare(strict_types=1);

namespace Ritmo\Rest\Reports;

use Ritmo\Admin\Reports\ReportsServices;
use Ritmo\Rest\RestRoutes;
use Ritmo\WordPress\Roles;
use WP_REST_Request;
use WP_REST_Response;

/**
 * The reports routes of ritmo/v1 (spec 06, 2.9). Reading takes ritmo_view; saving a review and
 * typing targets take ritmo_manage_rules; every route checks the REST nonce (RestRoutes::allowed).
 * The integration calls register() from RestRoutes::register().
 */
final class ReportRoutes
{
    private const PROPERTY = ['type' => 'string', 'required' => true, 'pattern' => '^[A-Za-z0-9][A-Za-z0-9_-]{0,31}$'];
    private const MONTH = ['type' => 'string', 'required' => false, 'pattern' => '^\d{4}-(0[1-9]|1[0-2])$'];

    private function __construct()
    {
    }

    public static function register(): void
    {
        foreach (['occupancy' => 'occupancy', 'month' => 'month', 'review' => 'review', 'weekly' => 'weekly', 'channels' => 'channels', 'rooms' => 'rooms'] as $path => $tab) {
            register_rest_route(RestRoutes::NAMESPACE, '/reports/' . $path, [
                'methods' => 'GET',
                'callback' => static fn (WP_REST_Request $r): WP_REST_Response => self::answer(self::endpoints()->report($tab, (string) $r['property'], (string) ($r['month'] ?? ''))),
                'permission_callback' => RestRoutes::allowed(Roles::VIEW),
                'args' => ['property' => self::PROPERTY, 'month' => self::MONTH],
            ]);
        }
        register_rest_route(RestRoutes::NAMESPACE, '/reports/review/snapshot', [
            'methods' => 'POST',
            'callback' => static fn (WP_REST_Request $r): WP_REST_Response => self::answer(self::endpoints()->snapshot((string) $r['property'], get_current_user_id())),
            'permission_callback' => RestRoutes::allowed(Roles::MANAGE_RULES),
            'args' => ['property' => self::PROPERTY],
        ]);
        register_rest_route(RestRoutes::NAMESPACE, '/reports/targets', [
            [
                'methods' => 'GET',
                'callback' => static fn (WP_REST_Request $r): WP_REST_Response => self::answer(self::endpoints()->report('targets', (string) $r['property'])),
                'permission_callback' => RestRoutes::allowed(Roles::VIEW),
                'args' => ['property' => self::PROPERTY],
            ],
            [
                'methods' => 'PUT',
                'callback' => static fn (WP_REST_Request $r): WP_REST_Response => self::answer(self::endpoints()->saveTargets((string) $r['property'], (string) $r['month'], is_array($r['fields']) ? $r['fields'] : [], get_current_user_id())),
                'permission_callback' => RestRoutes::allowed(Roles::MANAGE_RULES),
                'args' => ['property' => self::PROPERTY, 'month' => ['required' => true] + self::MONTH, 'fields' => ['type' => 'object', 'required' => true]],
            ],
        ]);
        register_rest_route(RestRoutes::NAMESPACE, '/runs/(?P<id>\d{1,18})/report', [
            'methods' => 'GET',
            'callback' => static fn (WP_REST_Request $r): WP_REST_Response => self::answer(self::endpoints()->runReport((int) $r['id'])),
            'permission_callback' => RestRoutes::allowed(Roles::VIEW),
            'args' => ['id' => ['type' => 'string', 'required' => true, 'pattern' => '^\d{1,18}$']],
        ]);
    }

    private static function endpoints(): ReportEndpoints
    {
        $services = ReportsServices::instance();
        $container = $services->services();

        return new ReportEndpoints(
            $services->reports(),
            $services->targetStore(),
            static fn (string $id) => $container->properties()->find($id),
            $services->scope(...),
            static fn (int $id): ?array => $container->runs()->find($id),
            $container->clock()->now(),
        );
    }

    /**
     * @param array{0: int, 1: array<string, mixed>} $answer
     */
    private static function answer(array $answer): WP_REST_Response
    {
        return new WP_REST_Response($answer[1], $answer[0]);
    }
}
