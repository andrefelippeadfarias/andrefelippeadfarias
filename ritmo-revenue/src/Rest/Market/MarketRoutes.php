<?php

declare(strict_types=1);

namespace Ritmo\Rest\Market;

use DateTimeImmutable;
use Ritmo\Admin\Market\MarketScreen;
use Ritmo\Admin\Market\MarketServices;
use Ritmo\Application\Market\MarketSettings;
use Ritmo\Domain\Config\InvalidConfig;
use Ritmo\Domain\Market\Series\MarketConfig;
use Ritmo\Domain\Support\Days;
use Ritmo\Rest\RestRoutes;
use Ritmo\WordPress\Roles;
use WP_REST_Request;
use WP_REST_Response;

/**
 * The market routes of ritmo/v1 (market spec 1.9): competitors, the two steps of a price file
 * import, the market by night and the latest imports. Every route checks the capability and the
 * REST nonce before anything is looked up (RestRoutes::allowed): reading needs ritmo_view,
 * changing needs ritmo_manage_rules. Inputs are declared with a type and a pattern and checked
 * again by the use cases. CSV downloads stay form actions (admin-post.php).
 */
final class MarketRoutes
{
    private const NAMESPACE = 'ritmo/v1';

    private const PROPERTY = ['type' => 'string', 'required' => true, 'pattern' => '^[A-Za-z0-9_-]{1,32}$'];

    private function __construct()
    {
    }

    public static function register(): void
    {
        register_rest_route(self::NAMESPACE, '/market/competitors', [
            [
                'methods' => 'GET',
                'callback' => static fn (WP_REST_Request $r): WP_REST_Response => self::answer(self::endpoints()->competitors((string) $r['property'])),
                'permission_callback' => RestRoutes::allowed(Roles::VIEW),
                'args' => ['property' => self::PROPERTY],
            ],
            [
                'methods' => 'POST',
                'callback' => static fn (WP_REST_Request $r): WP_REST_Response => self::answer(self::endpoints()->saveCompetitor((string) $r['property'], null, self::fields($r), get_current_user_id())),
                'permission_callback' => RestRoutes::allowed(Roles::MANAGE_RULES),
                'args' => ['property' => self::PROPERTY] + self::competitorArgs(),
            ],
        ]);
        register_rest_route(self::NAMESPACE, '/market/competitors/(?P<id>\d{1,18})', [
            'methods' => 'PUT',
            'callback' => static fn (WP_REST_Request $r): WP_REST_Response => self::answer(self::endpoints()->saveCompetitor((string) $r['property'], (int) $r['id'], self::fields($r), get_current_user_id())),
            'permission_callback' => RestRoutes::allowed(Roles::MANAGE_RULES),
            'args' => ['id' => ['type' => 'string', 'required' => true, 'pattern' => '^\d{1,18}$'], 'property' => self::PROPERTY] + self::competitorArgs(),
        ]);
        register_rest_route(self::NAMESPACE, '/market/import/preview', [
            'methods' => 'POST',
            'callback' => static fn (WP_REST_Request $r): WP_REST_Response => self::preview($r),
            'permission_callback' => RestRoutes::allowed(Roles::MANAGE_RULES),
            'args' => ['property' => self::PROPERTY],
        ]);
        register_rest_route(self::NAMESPACE, '/market/import/commit', [
            'methods' => 'POST',
            'callback' => static fn (WP_REST_Request $r): WP_REST_Response => self::answer(self::endpoints()->commit((string) $r['property'], (string) $r['token'], get_current_user_id())),
            'permission_callback' => RestRoutes::allowed(Roles::MANAGE_RULES),
            'args' => ['property' => self::PROPERTY, 'token' => ['type' => 'string', 'required' => true, 'pattern' => '^ritmo_csv_\d{1,18}_[0-9a-f]{16}$']],
        ]);
        register_rest_route(self::NAMESPACE, '/market/ruler', [
            'methods' => 'GET',
            'callback' => static fn (WP_REST_Request $r): WP_REST_Response => self::answer(self::endpoints()->ruler((string) $r['property'], self::date($r['from']), self::date($r['to']))),
            'permission_callback' => RestRoutes::allowed(Roles::VIEW),
            'args' => ['property' => self::PROPERTY, 'from' => self::dateArg(), 'to' => self::dateArg()],
        ]);
        register_rest_route(self::NAMESPACE, '/market/imports', [
            'methods' => 'GET',
            'callback' => static fn (WP_REST_Request $r): WP_REST_Response => self::answer(self::endpoints()->imports((string) $r['property'])),
            'permission_callback' => RestRoutes::allowed(Roles::VIEW),
            'args' => ['property' => self::PROPERTY],
        ]);
    }

    private static function preview(WP_REST_Request $request): WP_REST_Response
    {
        $config = self::config();
        $max = MarketScreen::maxBytes($config);
        $file = $request->get_file_params()['file'] ?? [];

        return self::answer(self::endpoints()->preview(
            (string) $request['property'],
            static fn () => MarketServices::instance()->uploads()->read(is_array($file) ? $file : [], $max, $config->csvMaxRows),
            get_current_user_id(),
            $max
        ));
    }

    /**
     * The competitor fields of the request that were sent (a PUT may send only some).
     *
     * @return array<string, mixed>
     */
    private static function fields(WP_REST_Request $request): array
    {
        $fields = [];
        foreach (array_keys(self::competitorArgs()) as $name) {
            if ($request->has_param($name)) {
                $fields[$name] = $request->get_param($name);
            }
        }

        return $fields;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private static function competitorArgs(): array
    {
        $text = static fn (int $max): array => ['type' => 'string', 'required' => false, 'maxLength' => $max];

        return [
            'name' => $text(190),
            'code' => ['type' => 'string', 'required' => false, 'pattern' => '^[a-z0-9-]{0,64}$'],
            'reference' => $text(190),
            'room_label' => $text(190),
            'tags' => ['type' => 'array', 'required' => false, 'maxItems' => 20, 'items' => ['type' => 'string', 'pattern' => '^[a-z0-9-]{1,32}$']],
            'factor' => ['type' => 'string', 'required' => false, 'pattern' => '^(\d{1,3}([.,]\d{1,3})?)?$'],
            'factor_source' => ['type' => 'string', 'required' => false, 'enum' => ['', 'measured', 'estimated']],
            'rating' => ['type' => 'string', 'required' => false, 'pattern' => '^(\d{1,3}([.,]\d{1,3})?)?$'],
            'is_own' => ['type' => 'boolean', 'required' => false],
            'active' => ['type' => 'boolean', 'required' => false],
        ];
    }

    /**
     * An optional night: a real calendar date written YYYY-MM-DD.
     *
     * @return array<string, mixed>
     */
    private static function dateArg(): array
    {
        return ['type' => 'string', 'required' => false, 'validate_callback' => static fn (mixed $value): bool => is_string($value) && ($value === '' || Days::isValid($value))];
    }

    private static function date(mixed $value): ?DateTimeImmutable
    {
        return is_string($value) && $value !== '' ? Days::date($value) : null;
    }

    private static function config(): MarketConfig
    {
        try {
            return MarketSettings::of(MarketServices::instance()->services()->ruleSets()->current());
        } catch (InvalidConfig) {
            return MarketConfig::neutral();
        }
    }

    private static function endpoints(): MarketEndpoints
    {
        $market = MarketServices::instance();
        $services = $market->services();

        return new MarketEndpoints(
            $services->properties(),
            $market->competitorService(),
            $market->board(),
            $market->import(),
            static fn () => $services->ruleSets()->current(),
            $services->clock(),
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
