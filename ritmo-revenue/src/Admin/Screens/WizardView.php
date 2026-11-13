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
use Ritmo\Application\Grid\PlannedNight;
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
            8 => [
                'property_id' => $property?->id ?? '',
                'rest_url' => rest_url('ritmo/v1/'),
                'rest_nonce' => wp_create_nonce('wp_rest'),
                'done' => $property !== null && ScreenServices::instance()->wizard()->progress($property->id)->done(8),
                'next_url' => Menu::url(Menu::SETTINGS, ['tab' => 'setup', 'step' => 9, 'property' => $property?->id ?? '']),
            ],
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
            'currency' => ScreenServices::instance()->listingSuggestions()->currency() ?? '',
            'currencies' => ['BRL', 'USD', 'EUR', 'GBP', 'ARS', 'MXN', 'CLP', 'COP', 'UYU', 'PEN', 'CAD', 'AUD'],
            'tiers' => array_values(array_map('strval', array_keys((array) ScreenServices::instance()->wizard()->rules()->get('pace.target_percentile')))),
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
        $suggested = $rows === [] && $property !== null ? ScreenServices::instance()->listingSuggestions()->rows(array_map(static fn (RoomType $r): string => $r->code, ScreenServices::instance()->services()->roomTypes()->all())) : [];

        return [
            'rows' => $suggested === [] ? [...$rows, $blank, $blank, $blank] : [...$suggested, $blank],
            'suggested' => $suggested !== [],
            'property_id' => $property?->id ?? '',
            'has_property' => $property !== null,
        ];
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
        $rooms = [];
        $bases = [];
        $types = [];
        foreach (ScreenServices::instance()->services()->roomTypes()->forProperty($property->id) as $room) {
            $rooms[$room->code] = $room->name;
            $bases[$room->code] = $room->base;
            $types[$room->code] = $room;
        }
        $summary = ScreenServices::instance()->wouldDo()->summary($property->id, $types);
        $minShadow = ScreenServices::instance()->wizard()->rules()->modes()->minShadowRuns;

        return [
            'empty' => $summary['run'] === null,
            'sentence' => sprintf(
                /* translators: 1: room nights read, 2: nights it would change, 3: up, 4: down */
                __('In shadow mode the plugin read %1$s room nights and would change %2$s nights: %3$s up, %4$s down.', 'ritmo-revenue'),
                number_format_i18n($summary['nights']),
                number_format_i18n($summary['total']),
                number_format_i18n($summary['up']),
                number_format_i18n($summary['down'])
            ),
            'no_market' => $summary['no_market'] === 0 ? '' : sprintf(
                /* translators: %s: number of room nights */
                _n('%s room night was left as it is because there is no market price to compare it with. Add this week\'s market prices in Market, then run in shadow again.', '%s room nights were left as they are because there is no market price to compare them with. Add this week\'s market prices in Market, then run in shadow again.', $summary['no_market'], 'ritmo-revenue'),
                number_format_i18n($summary['no_market'])
            ),
            'market_url' => Menu::url(Menu::MARKET, ['property' => $property->id]),
            'nearest' => array_map(static fn (array $c): array => [
                'title' => ($rooms[$c['room']] ?? $c['room']) . ' · ' . NightDate::short(Days::date($c['night'])),
                'field' => __('floor', 'ritmo-revenue'),
                'price' => NightText::bound($c['planned']->floorProposed, $c['planned']->floorPercent, $bases[$c['room']] ?? 0.0, $property->currency),
                'change' => self::change($c['planned'], $property->currency),
                'reason' => NightText::reason($c['planned']->reason),
            ], $summary['nearest']),
            'refused' => array_map(static fn (array $c): string => ($rooms[$c['room']] ?? $c['room']) . ' · ' . NightDate::short(Days::date($c['night'])) . ': ' . NightText::status($c['planned']), $summary['refused']),
            /* translators: %s: number of runs */
            'next' => sprintf(_n('Stay in shadow mode for at least %s run, then switch to approval in Operations, where every change waits for a person.', 'Stay in shadow mode for at least %s runs, then switch to approval in Operations, where every change waits for a person.', max(1, $minShadow), 'ritmo-revenue'), number_format_i18n(max(1, $minShadow))),
            'overview_url' => Menu::url(Menu::OVERVIEW, ['property' => $property->id]),
            'nights_url' => Menu::url(Menu::NIGHTS, ['property' => $property->id, 'only' => 'would_change']),
        ];
    }

    /**
     * @return array{text: string, note: string, sr: string}
     */
    private static function change(PlannedNight $planned, string $currency): array
    {
        $change = PriceText::change($planned->floorNow, $planned->floorProposed, $currency);
        if (!$planned->nowFromListing) {
            return $change;
        }
        $from = __('before: the listing minimum, as the night has no date rule yet', 'ritmo-revenue');
        $note = $change['note'] === '' ? $from : $change['note'] . '; ' . $from;

        return ['text' => $change['text'], 'note' => $note, 'sr' => $change['sr'] . ' (' . $from . ')'];
    }

    private static function plain(float $amount): string
    {
        return ApprovalActions::plain($amount);
    }
}
