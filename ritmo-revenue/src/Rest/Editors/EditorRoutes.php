<?php

declare(strict_types=1);

namespace Ritmo\Rest\Editors;

use DateTimeImmutable;
use Ritmo\Admin\Editors\EditorServices;
use Ritmo\Admin\Editors\VersionsView;
use Ritmo\Rest\RestRoutes;
use Ritmo\WordPress\Roles;
use WP_REST_Request;
use WP_REST_Response;

/**
 * The rules routes of ritmo/v1 (read only, ritmo_view): the versions of the rules and the
 * comparison of two versions, which the Versions tab loads when a person opens the changes of a
 * version. Every route checks the capability and the REST nonce first (RestRoutes::allowed).
 */
final class EditorRoutes
{
    private const NAMESPACE = 'ritmo/v1';

    private const VERSION = ['type' => 'string', 'required' => true, 'pattern' => '^\d{1,9}$'];

    private function __construct()
    {
    }

    public static function register(): void
    {
        register_rest_route(self::NAMESPACE, '/rules/versions', [
            'methods' => 'GET',
            'callback' => static fn (WP_REST_Request $r): WP_REST_Response => self::answer(self::endpoints((string) $r['property'])->versions((int) ($r['page'] ?? 1))),
            'permission_callback' => RestRoutes::allowed(Roles::VIEW),
            'args' => [
                'page' => ['type' => 'string', 'required' => false, 'pattern' => '^\d{1,6}$'],
                'property' => ['type' => 'string', 'required' => false, 'pattern' => '^[A-Za-z0-9_-]{0,32}$'],
            ],
        ]);
        register_rest_route(self::NAMESPACE, '/rules/compare', [
            'methods' => 'GET',
            'callback' => static fn (WP_REST_Request $r): WP_REST_Response => self::answer(self::endpoints((string) $r['property'])->compare((int) $r['from'], (int) $r['to'])),
            'permission_callback' => RestRoutes::allowed(Roles::VIEW),
            'args' => [
                'from' => self::VERSION,
                'to' => self::VERSION,
                'property' => ['type' => 'string', 'required' => false, 'pattern' => '^[A-Za-z0-9_-]{0,32}$'],
            ],
        ]);
    }

    /**
     * Money values read in the currency of the property asked for (or of the first property).
     */
    private static function endpoints(string $propertyId): EditorEndpoints
    {
        $editors = EditorServices::instance();
        $properties = $editors->services()->properties();
        $property = ($propertyId === '' ? null : $properties->find($propertyId)) ?? ($properties->all()[0] ?? null);

        return new EditorEndpoints(
            $editors->history(),
            VersionsView::person(...),
            static fn (DateTimeImmutable $at): string => wp_date(get_option('date_format') . ' ' . get_option('time_format'), $at->getTimestamp()),
            $property?->currency ?? '',
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
