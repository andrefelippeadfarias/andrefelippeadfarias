<?php

declare(strict_types=1);

namespace Ritmo\Admin\Screens;

use Ritmo\Admin\Components\Capability;
use Ritmo\Admin\Components\Input;
use Ritmo\Admin\Components\Menu;
use Ritmo\Admin\Components\Money;
use Ritmo\Admin\Components\NightDate;
use Ritmo\Admin\Components\PriceText;
use Ritmo\Admin\Components\Ratio;
use Ritmo\Application\Dashboard\Setup\WizardProgress;
use Ritmo\Domain\Model\Property;
use Ritmo\Domain\Model\RoomType;
use Ritmo\Domain\Support\Days;
use Ritmo\Domain\Support\Num;
use Ritmo\WordPress\ConnectorFactory;
use Ritmo\WordPress\Scheduler;

/**
 * Settings › Setup, the wizard (admin spec 5.1): the nine steps with what is done (derived from
 * the data), and the form of the step shown. A step the user may not do is shown read-only with
 * "Ask an administrator to do this step".
 */
final class WizardView
{
    private const CAPABILITIES = ['view' => Capability::VIEW, 'connections' => Capability::MANAGE_CONNECTIONS, 'rules' => Capability::MANAGE_RULES, 'safety' => Capability::MANAGE_SAFETY];

    private function __construct()
    {
    }

    /**
     * @return array<string, mixed>
     */
    public static function build(Page $page): array
    {
        $services = ScreenServices::instance();
        $property = $page->property;
        $progress = $services->wizard()->progress($property?->id);
        $asked = Input::query()->int('step', 0, 0, 9);
        $step = $asked >= 1 ? $asked : $progress->current();
        if ($step === 8 && $progress->done(8)) {
            $step = 9;
        }
        $can = Capability::can(self::CAPABILITIES[WizardProgress::CAPABILITY[$step]]);

        return [
            'steps' => self::steps($progress, $step, $property),
            'step' => $step,
            'template' => match ($step) {
                1 => 'welcome',
                2, 3 => 'connection',
                4 => 'property',
                5 => 'rooms',
                6 => 'rules',
                7 => 'safety',
                8 => 'shadow',
                default => 'would_do',
            },
            'can' => $can,
            'complete' => $progress->complete(),
            'property_id' => $property?->id ?? '',
            'content' => self::content($step, $property),
            'back' => $step > 1 ? Menu::url(Menu::SETTINGS, ['tab' => 'setup', 'step' => $step - 1, 'property' => $property?->id ?? '']) : '',
            'safety_note' => __('The capability to change safety limits is never given to a plugin role by default: an administrator gives it to a person by hand.', 'ritmo-revenue'),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function steps(WizardProgress $progress, int $current, ?Property $property): array
    {
        $titles = [
            1 => __('Welcome and checks', 'ritmo-revenue'),
            2 => __('Connect the PMS', 'ritmo-revenue'),
            3 => __('Connect the pricing system', 'ritmo-revenue'),
            4 => __('Property', 'ritmo-revenue'),
            5 => __('Rooms', 'ritmo-revenue'),
            6 => __('Rules and calendar', 'ritmo-revenue'),
            7 => __('Safety limits', 'ritmo-revenue'),
            8 => __('First shadow run', 'ritmo-revenue'),
            9 => __('What the plugin would do', 'ritmo-revenue'),
        ];
        $steps = [];
        foreach ($titles as $number => $title) {
            $steps[] = [
                'number' => $number,
                'title' => $title,
                'done' => $number < 9 && $progress->done($number),
                'current' => $number === $current,
                'url' => Menu::url(Menu::SETTINGS, ['tab' => 'setup', 'step' => $number, 'property' => $property?->id ?? '']),
            ];
        }

        return $steps;
    }

    /**
     * @return array<string, mixed>
     */
    private static function content(int $step, ?Property $property): array
    {
        return match ($step) {
            1 => self::welcome(),
            2, 3 => self::connection($step),
            4 => self::propertyStep(),
            5 => self::rooms($property),
            6 => self::rules(),
            7 => self::safety($property),
            8 => ['property_id' => $property?->id ?? '', 'rest_url' => rest_url('ritmo/v1/'), 'rest_nonce' => wp_create_nonce('wp_rest')],
            default => self::wouldDo($property),
        };
    }

    /**
     * @return array<string, mixed>
     */
    private static function welcome(): array
    {
        $services = ScreenServices::instance()->services();
        $timezone = wp_timezone_string();
        $cronOff = Scheduler::state()['disabled'];

        return ['checks' => [
            ['ok' => PHP_VERSION_ID >= 80100, 'text' => sprintf(/* translators: %s: version */ __('PHP %s', 'ritmo-revenue'), PHP_VERSION)],
            ['ok' => function_exists('sodium_crypto_secretbox'), 'text' => __('Encryption of the keys (sodium)', 'ritmo-revenue')],
            ['ok' => $services->vault()->protection() !== 'database_only', 'text' => $services->vault()->protection() === 'database_only'
                ? __('Keys are protected only by the database: add RITMO_SECRET_KEY to wp-config.php.', 'ritmo-revenue')
                : __('Keys are protected by a key in wp-config.php.', 'ritmo-revenue')],
            ['ok' => !$cronOff, 'text' => $cronOff
                ? __('WP-Cron is off: a server cron must call wp-cron.php every 5 minutes.', 'ritmo-revenue')
                : __('WP-Cron is on.', 'ritmo-revenue')],
            ['ok' => in_array($timezone, timezone_identifiers_list(), true), 'text' => sprintf(/* translators: %s: timezone */ __('Site timezone: %s', 'ritmo-revenue'), $timezone)],
        ]];
    }

    /**
     * @return array<string, mixed>
     */
    private static function connection(int $step): array
    {
        $services = ScreenServices::instance();
        $store = $services->services()->store();
        $provider = $step === 2 ? 'beds24' : 'pricelabs';
        $last = $services->connectionTests()->last($provider);
        $status = $services->services()->vault()->status(ConnectionActions::PROVIDERS[$provider]);

        return [
            'provider' => $provider,
            'saved' => $status->configured,
            'token_kind' => (string) $store->get(ConnectorFactory::BEDS24_TOKEN_KIND, 'refresh'),
            'property_ids' => (string) $store->get(ConnectorFactory::BEDS24_PROPERTY_IDS, ''),
            'pms' => (string) $store->get(ConnectorFactory::PRICELABS_PMS, ConnectorFactory::DEFAULT_PRICELABS_PMS),
            'result' => $last === null ? '' : ConnectionText::result($provider, $last['reason'], $last['counts']),
            'ok' => $last['ok'] ?? false,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function propertyStep(): array
    {
        $site = wp_timezone_string();

        return [
            'timezones' => timezone_identifiers_list(),
            'timezone' => in_array($site, timezone_identifiers_list(), true) ? $site : '',
            'currencies' => ['BRL', 'USD', 'EUR', 'GBP', 'ARS', 'MXN', 'CLP', 'COP', 'UYU', 'PEN', 'CAD', 'AUD'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function rooms(?Property $property): array
    {
        $rooms = $property === null ? [] : ScreenServices::instance()->services()->roomTypes()->forProperty($property->id);
        $rows = array_map(static fn (RoomType $r): array => [
            'code' => $r->code, 'name' => $r->name, 'units' => (string) $r->units, 'pms_ref' => $r->pmsRef, 'pricing_ref' => $r->pricingRef,
            'base' => self::plain($r->base), 'min' => self::plain($r->min), 'max' => self::plain($r->max), 'tags' => implode(', ', $r->tags),
        ], $rooms);
        $blank = ['code' => '', 'name' => '', 'units' => '1', 'pms_ref' => '', 'pricing_ref' => '', 'base' => '', 'min' => '', 'max' => '', 'tags' => ''];

        return ['rows' => [...$rows, $blank, $blank, $blank], 'property_id' => $property?->id ?? '', 'has_property' => $property !== null];
    }

    /**
     * @return array<string, mixed>
     */
    private static function rules(): array
    {
        $weekend = ScreenServices::instance()->wizard()->rules()->weekendDays();
        $names = [__('Monday', 'ritmo-revenue'), __('Tuesday', 'ritmo-revenue'), __('Wednesday', 'ritmo-revenue'), __('Thursday', 'ritmo-revenue'), __('Friday', 'ritmo-revenue'), __('Saturday', 'ritmo-revenue'), __('Sunday', 'ritmo-revenue')];

        return ['days' => array_map(static fn (int $d, string $n): array => ['value' => $d, 'name' => $n, 'checked' => in_array($d, $weekend, true)], array_keys($names), $names)];
    }

    /**
     * @return array<string, mixed>
     */
    private static function safety(?Property $property): array
    {
        $rules = ScreenServices::instance()->wizard()->rules();
        $fraction = (float) $rules->get('floors.fraction_of_reference_min');
        $rooms = $property === null ? [] : ScreenServices::instance()->services()->roomTypes()->forProperty($property->id);
        $currency = $property?->currency ?? 'USD';

        return [
            'property_id' => $property?->id ?? '',
            'fraction' => Ratio::toPercentText($fraction),
            'weekly' => Ratio::toPercentText((float) $rules->get('safety.weekly_listing_change_max')),
            'listing_changes' => (bool) $rules->get('write.listing_changes_enabled'),
            'autonomous' => (bool) $rules->get('modes.autonomous_enabled'),
            'rooms' => array_map(static fn (RoomType $r): array => [
                'code' => $r->code,
                'name' => $r->name,
                'listing_min' => PriceText::amount($r->min, $currency),
                'reference' => self::plain($r->referenceMin),
                'floor' => sprintf(
                    /* translators: 1: lowest price, 2: share, 3: reference minimum */
                    __('No price below %1$s = %2$s%% of the reference minimum %3$s', 'ritmo-revenue'),
                    Money::format(Num::roundHalfEven($r->referenceMin * $fraction, 2), $currency),
                    Ratio::toPercentText($fraction),
                    Money::format($r->referenceMin, $currency)
                ),
            ], $rooms),
            'symbol' => Money::symbol($currency),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function wouldDo(?Property $property): array
    {
        if ($property === null) {
            return ['empty' => true];
        }
        $summary = ScreenServices::instance()->wouldDo()->summary($property->id);
        $rooms = [];
        $bases = [];
        foreach (ScreenServices::instance()->services()->roomTypes()->forProperty($property->id) as $room) {
            $rooms[$room->code] = $room->name;
            $bases[$room->code] = $room->base;
        }
        $minShadow = ScreenServices::instance()->wizard()->rules()->modes()->minShadowRuns;

        return [
            'empty' => $summary['run'] === null,
            'sentence' => sprintf(
                /* translators: 1: nights it would change, 2: up, 3: down */
                __('In shadow mode the plugin would change %1$s nights: %2$s up, %3$s down.', 'ritmo-revenue'),
                number_format_i18n($summary['total']),
                number_format_i18n($summary['up']),
                number_format_i18n($summary['down'])
            ),
            'nearest' => array_map(static fn (array $c): array => [
                'title' => ($rooms[$c['room']] ?? $c['room']) . ' · ' . NightDate::short(Days::date($c['night'])),
                'price' => NightText::bound($c['planned']->floorProposed, $c['planned']->floorPercent, $bases[$c['room']] ?? 0.0, $property->currency),
                'change' => PriceText::change($c['planned']->floorNow, $c['planned']->floorProposed, $property->currency),
                'reason' => NightText::reason($c['planned']->reason),
            ], $summary['nearest']),
            'refused' => array_map(static fn (array $c): string => ($rooms[$c['room']] ?? $c['room']) . ' · ' . NightDate::short(Days::date($c['night'])), $summary['refused']),
            /* translators: %s: number of runs */
            'next' => sprintf(_n('Stay in shadow mode for at least %s run, then switch to approval in Operations, where every change waits for a person.', 'Stay in shadow mode for at least %s runs, then switch to approval in Operations, where every change waits for a person.', max(1, $minShadow), 'ritmo-revenue'), number_format_i18n(max(1, $minShadow))),
            'overview_url' => Menu::url(Menu::OVERVIEW, ['property' => $property->id]),
            'nights_url' => Menu::url(Menu::NIGHTS, ['property' => $property->id, 'only' => 'would_change']),
        ];
    }

    private static function plain(float $amount): string
    {
        return ApprovalActions::plain($amount);
    }
}
