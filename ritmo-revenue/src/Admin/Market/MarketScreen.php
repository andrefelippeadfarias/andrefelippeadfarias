<?php

declare(strict_types=1);

namespace Ritmo\Admin\Market;

use DateTimeImmutable;
use Ritmo\Admin\Components\Capability;
use Ritmo\Admin\Components\Input;
use Ritmo\Admin\Components\Menu;
use Ritmo\Admin\Components\Money;
use Ritmo\Admin\Components\NightDate;
use Ritmo\Admin\Components\Screen;
use Ritmo\Admin\Components\Tab;
use Ritmo\Admin\Editors\ChoiceLabels;
use Ritmo\Admin\Editors\EditorPage;
use Ritmo\Application\Market\MarketReminder;
use Ritmo\Application\Market\MarketSettings;
use Ritmo\Domain\Config\InvalidConfig;
use Ritmo\Domain\Config\RuleSet;
use Ritmo\Domain\Market\Series\MarketConfig;
use Ritmo\Domain\Support\Days;

/**
 * The Market screen (spec 05, 5.9; spec 06, 1.9): the market per night with where each value
 * came from and its age, the competitors, the prices, the import of a price file in two steps
 * and the exports. Changing market data never changes a price by itself.
 */
final class MarketScreen
{
    public const PRICES_SOURCES = ['csv', 'manual', 'booking'];

    private function __construct()
    {
    }

    /**
     * @return list<Screen>
     */
    public static function screens(): array
    {
        return [new Screen(Menu::MARKET, __('Market', 'ritmo-revenue'), Capability::VIEW, self::tabs(), self::render(...))];
    }

    /**
     * @return list<Tab>
     */
    public static function tabs(): array
    {
        $v = Capability::VIEW;

        return [
            new Tab('summary', __('Market by night', 'ritmo-revenue'), $v),
            new Tab('competitors', __('Competitors', 'ritmo-revenue'), $v),
            new Tab('prices', __('Prices', 'ritmo-revenue'), $v),
            new Tab('import', __('Import', 'ritmo-revenue'), Capability::MANAGE_RULES),
            new Tab('export', __('Export', 'ritmo-revenue'), $v),
        ];
    }

    public static function render(): void
    {
        $page = EditorPage::open(Menu::MARKET, __('Market', 'ritmo-revenue'), Capability::VIEW, self::tabs());
        if ($page->property === null) {
            $page->render('admin/market/screen', ['no_property' => true]);

            return;
        }
        $services = MarketServices::instance();
        $rules = $services->services()->ruleSets()->current() ?? RuleSet::defaults();
        try {
            $config = MarketSettings::of($rules);
        } catch (InvalidConfig) {
            $config = MarketConfig::neutral();
        }
        $query = Input::query();
        $today = $services->services()->clock()->today($page->property->timezone);
        $view = [
            'no_property' => false,
            'may_edit' => Capability::can(Capability::MANAGE_RULES),
            'freshness' => $services->freshness()->check($page->property->id, $today, $page->property->timezone, $config),
            'config' => $config,
            'competitors' => $services->board()->competitors($page->property->id),
        ];
        $view += match ($page->tab) {
            'competitors' => ['edit' => $query->int('edit', 0, 0)],
            'prices' => self::prices($page, $query),
            'import' => ['preview' => $services->import()->pending($page->property->id, $query->text('preview', 64), get_current_user_id()), 'imports' => $services->board()->imports($page->property->id), 'max_bytes' => self::maxBytes($config)],
            'export' => ['today' => Days::key($today), 'until' => Days::key(Days::add($today, 89))],
            default => self::summary($page, $rules, $config, $query->date('from') ?? $today, $today) + ['gaps' => self::gaps($page, $rules, $config, $today), 'gap_nights' => MarketReminder::GAP_NIGHTS],
        };
        $page->render('admin/market/screen', $view);
    }

    /**
     * The upload limit: the market setting, never above the site's own limit.
     */
    public static function maxBytes(MarketConfig $config): int
    {
        return (int) min($config->csvMaxBytes, wp_max_upload_size());
    }

    /**
     * @return array<string, mixed>
     */
    private static function summary(EditorPage $page, RuleSet $rules, MarketConfig $config, DateTimeImmutable $from, DateTimeImmutable $today): array
    {
        $from = max($from, $today);
        $nights = MarketServices::instance()->board()->nights($page->property, $rules, $config, $from);
        $currency = $page->currency();
        $rows = [];
        $series = [];
        foreach ($nights as $night) {
            $cells = [];
            foreach ($night['series'] as $name => $value) {
                $series[$name] = true;
                $cells[$name] = [
                    'median' => isset($value->values['50']) ? Money::format((float) $value->values['50'], $currency) : '',
                    'range' => isset($value->values['25'], $value->values['75']) ? Money::format((float) $value->values['25'], $currency) . ' – ' . Money::format((float) $value->values['75'], $currency) : '',
                    'from' => MarketText::origin($value),
                    'competitors' => $value->competitors,
                ];
            }
            $anchor = $night['anchor'];
            $rows[] = [
                'night' => NightDate::short($night['night']),
                'type' => ChoiceLabels::of('calendar.holidays.*.night_type', $night['type']),
                'cells' => $cells,
                'anchor' => $anchor === null || $anchor->n === 0 ? '' : Money::format($anchor->p50, $currency),
                'age' => $night['age_days'],
                'old' => $night['age_days'] !== null && $night['age_days'] > $config->staleAfterDays,
                'small' => $night['small_sample'],
            ];
        }

        return [
            'rows' => $rows,
            'series' => array_keys($series),
            'from' => Days::key($from),
            'from_text' => NightDate::long($from),
            'previous' => $from > $today ? Days::key(max($today, Days::add($from, -30))) : null,
            'next' => Days::key(Days::add($from, 30)),
        ];
    }

    /**
     * The coming nights the sales ladder would price without a market value, in words.
     *
     * @return list<array{night: string, missing: string}>
     */
    private static function gaps(EditorPage $page, RuleSet $rules, MarketConfig $config, DateTimeImmutable $today): array
    {
        return array_map(static fn (array $gap): array => [
            'night' => NightDate::short($gap['night']),
            'missing' => implode('; ', array_map(MarketText::point(...), $gap['missing'])),
        ], MarketServices::instance()->reminder()->check($page->property, $rules, $config, $today)['gaps']);
    }

    /**
     * @return array<string, mixed>
     */
    private static function prices(EditorPage $page, Input $query): array
    {
        $filters = array_filter([
            'competitor' => $query->int('competitor', 0, 0) ?: null,
            'from' => $query->date('night_from'),
            'to' => $query->date('night_to'),
            'source' => $query->choice('source', self::PRICES_SOURCES, '') ?: null,
        ], static fn (mixed $v): bool => $v !== null);
        $pageNumber = $query->int('paged', 1, 1, 100000);

        return ['filters' => $filters, 'prices' => MarketServices::instance()->board()->prices($page->property->id, $filters, $pageNumber), 'page_number' => $pageNumber];
    }
}
