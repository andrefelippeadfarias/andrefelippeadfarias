<?php

declare(strict_types=1);

namespace Ritmo\Rest\Ai;

use Ritmo\Admin\Ai\AiCapability;
use Ritmo\Admin\Ai\AiData;
use Ritmo\Admin\Ai\AiScreen;
use Ritmo\Admin\Ai\AiServices;
use Ritmo\Admin\Ai\AiWords;
use Ritmo\Domain\Ai\AiDraft;
use Ritmo\Domain\Ai\PromptBuilder;
use Ritmo\Rest\RestRoutes;
use Ritmo\WordPress\Roles;
use WP_REST_Request;
use WP_REST_Response;

/**
 * The assistant's routes of ritmo/v1. Each checks the REST nonce and its capability before
 * anything (RestRoutes::allowed): ritmo_use_ai to ask, ritmo_manage_connections to test the key.
 * None of them changes a price, an order, a rule, a mode or the emergency switch; a draft is
 * queued for a person.
 *
 *   GET  /ai/status                 ready or why not, the month's spend and cap
 *   POST /ai/drafts                 {property, request}: a checked draft (201) or why not
 *   POST /ai/reports/weekly         {property}: the weekly panel in words
 *   POST /ai/runs/{id}/report       a run's report in words
 *   POST /ai/alerts/{id}/explain    an alert explained, with the next step
 *   POST /ai/test                   the models of the key
 */
final class AiRoutes
{
    private const NAMESPACE = 'ritmo/v1';

    private const PROPERTY = ['type' => 'string', 'required' => false, 'pattern' => '^[A-Za-z0-9_-]{0,32}$'];

    private function __construct()
    {
    }

    public static function register(): void
    {
        $use = RestRoutes::allowed(AiCapability::USE_AI);
        register_rest_route(self::NAMESPACE, '/ai/status', [
            'methods' => 'GET',
            'callback' => static fn (): WP_REST_Response => self::answer(self::endpoints()->status()),
            'permission_callback' => $use,
        ]);
        register_rest_route(self::NAMESPACE, '/ai/drafts', [
            'methods' => 'POST',
            'callback' => static fn (WP_REST_Request $r): WP_REST_Response => self::answer(self::endpoints()->draft((string) $r['property'], sanitize_textarea_field((string) $r['request']))),
            'permission_callback' => $use,
            'args' => ['property' => self::PROPERTY, 'request' => ['type' => 'string', 'required' => true, 'maxLength' => PromptBuilder::REQUEST_MAX * 4]],
        ]);
        register_rest_route(self::NAMESPACE, '/ai/reports/weekly', [
            'methods' => 'POST',
            'callback' => static fn (WP_REST_Request $r): WP_REST_Response => self::answer(self::endpoints()->weekly((string) $r['property'])),
            'permission_callback' => $use,
            'args' => ['property' => self::PROPERTY],
        ]);
        register_rest_route(self::NAMESPACE, '/ai/runs/(?P<id>\d{1,12})/report', [
            'methods' => 'POST',
            'callback' => static fn (WP_REST_Request $r): WP_REST_Response => self::answer(self::endpoints()->runReport((int) $r['id'])),
            'permission_callback' => $use,
        ]);
        register_rest_route(self::NAMESPACE, '/ai/alerts/(?P<id>\d{1,12})/explain', [
            'methods' => 'POST',
            'callback' => static fn (WP_REST_Request $r): WP_REST_Response => self::answer(self::endpoints()->explainAlert((int) $r['id'])),
            'permission_callback' => $use,
        ]);
        register_rest_route(self::NAMESPACE, '/ai/test', [
            'methods' => 'POST',
            'callback' => static fn (): WP_REST_Response => self::answer(self::endpoints()->test()),
            'permission_callback' => RestRoutes::allowed(Roles::MANAGE_CONNECTIONS),
        ]);
    }

    private static function endpoints(): AiEndpoints
    {
        $services = AiServices::instance();

        return new AiEndpoints(
            $services->assistant(),
            $services->drafts(),
            AiWords::stopped(...),
            static function (string $propertyId, string $text): ?array {
                $property = AiData::property($propertyId);

                return $property === null ? null : AiData::request($property, $text) + ['property' => $property->id];
            },
            static function (string $propertyId): ?array {
                $property = AiData::property($propertyId);

                return $property === null ? null : AiData::weekly($property);
            },
            AiData::run(...),
            AiData::alert(...),
            static function (string $id, string $request, string $created, AiDraft $draft, string $propertyId) use ($services): array {
                $property = AiData::property($propertyId);
                $rooms = [];
                foreach ($property === null ? [] : $services->services()->roomTypes()->forProperty($property->id) as $room) {
                    $rooms[$room->code] = $room->name;
                }

                return AiScreen::draftRow($id, $request, $created, $draft, $rooms, $property?->currency ?? '');
            },
            static fn (string $what): string => match ($what) {
                'property' => __('That property does not exist.', 'ritmo-revenue'),
                'run' => __('That run does not exist.', 'ritmo-revenue'),
                'alert' => __('That alert does not exist.', 'ritmo-revenue'),
                default => __('A request has from 1 to 1000 characters.', 'ritmo-revenue'),
            },
            AiData::locale(),
            get_current_user_id(),
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
