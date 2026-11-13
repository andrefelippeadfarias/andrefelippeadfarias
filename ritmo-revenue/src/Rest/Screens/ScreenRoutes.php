<?php

declare(strict_types=1);

namespace Ritmo\Rest\Screens;

use Ritmo\Admin\Components\Labels;
use Ritmo\Admin\Screens\ConnectionActions;
use Ritmo\Admin\Screens\ScreenServices;
use Ritmo\Rest\RestRoutes;
use Ritmo\WordPress\Roles;
use WP_REST_Request;
use WP_REST_Response;

/**
 * The REST routes of the central screens (admin spec Appendix A), only where a page waits for a
 * result: the status of an approval (polling), the closure of a selection, a connection test and
 * the alert buttons. Each route checks the capability and the REST nonce before looking anything
 * up (RestRoutes::allowed); the endpoints check again what each person may do.
 */
final class ScreenRoutes
{
    private const NAMESPACE = 'ritmo/v1';

    private const ID = ['type' => 'string', 'required' => true, 'pattern' => '^\d{1,18}$'];

    private function __construct()
    {
    }

    public static function register(): void
    {
        register_rest_route(self::NAMESPACE, '/approvals/(?P<id>\d{1,18})', [
            'methods' => 'GET',
            'callback' => static fn (WP_REST_Request $r): WP_REST_Response => self::answer(self::endpoints()->approvalStatus((int) $r['id'])),
            'permission_callback' => RestRoutes::allowed(Roles::VIEW),
            'args' => ['id' => self::ID],
        ]);
        register_rest_route(self::NAMESPACE, '/approvals/(?P<id>\d{1,18})/selection', [
            'methods' => 'POST',
            'callback' => static fn (WP_REST_Request $r): WP_REST_Response => self::answer(self::endpoints()->selection((int) $r['id'], (string) $r->get_param('property'), $r->get_param('keys'))),
            'permission_callback' => RestRoutes::allowed(Roles::APPROVE),
            'args' => ['id' => self::ID, 'property' => ['type' => 'string', 'required' => true, 'pattern' => '^[A-Za-z0-9_-]{1,32}$'], 'keys' => ['type' => 'array', 'required' => true, 'maxItems' => 5000, 'items' => ['type' => 'string']]],
        ]);
        register_rest_route(self::NAMESPACE, '/connections/(?P<provider>beds24|pricelabs)/test', [
            'methods' => 'POST',
            'callback' => static fn (WP_REST_Request $r): WP_REST_Response => self::answer(self::endpoints()->connectionTest((string) $r['provider'])),
            'permission_callback' => RestRoutes::allowed(Roles::MANAGE_CONNECTIONS),
            'args' => ['provider' => ['type' => 'string', 'required' => true, 'enum' => ['beds24', 'pricelabs']]],
        ]);
        register_rest_route(self::NAMESPACE, '/alerts/(?P<id>\d{1,18})/acknowledge', [
            'methods' => 'POST',
            'callback' => static fn (WP_REST_Request $r): WP_REST_Response => self::answer(self::endpoints()->acknowledge((int) $r['id'], get_current_user_id(), current_user_can(Roles::APPROVE))),
            'permission_callback' => RestRoutes::allowed(Roles::VIEW),
            'args' => ['id' => self::ID],
        ]);
        register_rest_route(self::NAMESPACE, '/alerts/(?P<id>\d{1,18})/resolve', [
            'methods' => 'POST',
            'callback' => static fn (WP_REST_Request $r): WP_REST_Response => self::answer(self::endpoints()->resolve((int) $r['id'], get_current_user_id(), current_user_can(Roles::APPROVE))),
            'permission_callback' => RestRoutes::allowed(Roles::APPROVE),
            'args' => ['id' => self::ID],
        ]);
    }

    private static function endpoints(): ScreenEndpoints
    {
        $services = ScreenServices::instance();

        return new ScreenEndpoints(
            $services->approvals(),
            $services->services()->stack()->alerts(),
            ConnectionActions::run(...),
            Labels::approvalStatus(...),
        );
    }

    /**
     * @param array{0: int, 1: array<string, mixed>} $answer status and body
     */
    private static function answer(array $answer): WP_REST_Response
    {
        return new WP_REST_Response($answer[1], $answer[0]);
    }
}
