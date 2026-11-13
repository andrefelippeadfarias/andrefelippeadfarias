<?php

declare(strict_types=1);

namespace Ritmo\Admin\Screens;

use DateTimeImmutable;
use Ritmo\Admin\Components\Capability;
use Ritmo\Admin\Components\ErrorText;
use Ritmo\Admin\Components\Input;
use Ritmo\Admin\Components\Labels;
use Ritmo\Admin\Components\Menu;
use Ritmo\Admin\Components\NightDate;
use Ritmo\Application\Grid\NightFilter;
use Ritmo\Application\Grid\NightGrid;
use Ritmo\Application\Grid\NightGridQuery;
use Ritmo\Domain\Model\Property;
use Ritmo\Domain\Support\Days;
use Throwable;

/**
 * Nights (admin spec 5.3): the grid of rooms by nights with the filters, and the night detail on
 * the same screen (its own URL, decision D7). Read only; releasing a freeze is a form of the
 * detail, for whoever approves.
 */
final class NightsScreen
{
    public const WINDOWS = [7, 14, 30, 60, 90];

    private function __construct()
    {
    }

    public static function render(): void
    {
        $page = Page::open(Menu::NIGHTS, __('Nights', 'ritmo-revenue'), Capability::VIEW);
        $property = $page->property;
        if ($property === null) {
            $page->render('nights', ['empty' => true, 'setup_url' => Menu::url(Menu::SETTINGS, ['tab' => 'setup'])]);

            return;
        }
        $query = Input::query();
        $services = ScreenServices::instance();
        $today = $services->services()->clock()->today($property->timezone);
        $filters = [
            'from' => $query->date('from') ?? $today,
            'nights' => (int) $query->choice('nights', array_map('strval', self::WINDOWS), '30'),
            'show' => $query->choice('show', NightText::SHOW, 'price'),
            'only' => NightFilter::from($query->text('only', 32)),
            'rooms' => $query->list('rooms', '/^[A-Za-z0-9_-]{1,32}$/', 200),
            'room_page' => $query->int('room_page', 1, 1, 100),
        ];
        $view = ['empty' => false, 'filters' => self::filterView($filters, $page, $property), 'detail' => null];
        $room = $query->text('room', 32);
        $night = $query->date('night');
        if ($room !== '' && $night !== null) {
            $view['detail'] = NightDetailView::build($property, $room, $night, $page);
        }
        try {
            $grid = $services->grid()->window($property->id, $filters['from'], $filters['nights'], $filters['rooms'], $filters['only'], $filters['room_page']);
            $view['grid'] = self::gridView($grid, $filters, $page);
        } catch (Throwable $failure) {
            $view['grid'] = ['ok' => false, 'error' => ErrorText::from($failure)];
        }
        $page->render('nights', $view);
    }

    /**
     * @param array<string, mixed> $filters
     *
     * @return array<string, mixed>
     */
    private static function gridView(NightGrid $grid, array $filters, Page $page): array
    {
        $currency = $grid->property->currency;
        $columns = array_map(static fn (DateTimeImmutable $n): array => [
            'weekday' => NightDate::weekday($n),
            'day' => NightDate::day($n),
            'holiday' => $grid->holidays[Days::key($n)] ?? '',
        ], $grid->nights);
        $rows = [];
        foreach ($grid->rooms as $room) {
            $cells = [];
            foreach ($grid->nights as $night) {
                $cell = $grid->cell($room->code, Days::key($night));
                $cells[] = $cell === null ? null : [
                    'text' => NightText::visible($cell, $filters['show'], $currency),
                    'label' => NightText::accessible($cell, $room->name, $currency),
                    'url' => $page->url(self::keep($filters) + ['room' => $room->code, 'night' => Days::key($night)]) . '#ritmo-night-detail',
                    'tone' => $cell->soldOut() || $cell->closedWithoutBooking() ? 'neutral' : self::tone($cell->planned->paceCategory()),
                    'mark' => $cell->soldOut() || $cell->closedWithoutBooking() || $filters['show'] === 'pace' ? '' : self::mark($cell->planned->paceCategory()),
                    'arrow' => match ($cell->planned->direction()) {
                        'up' => '↑',
                        'down' => '↓',
                        default => '',
                    },
                    'frozen' => $cell->frozen,
                    'order' => $cell->orderIds !== [],
                    'alert' => $cell->alertCount > 0,
                    'would' => $cell->planned->status === 'would_write',
                ];
            }
            $rows[] = ['code' => $room->code, 'name' => $room->name, 'cells' => $cells];
        }
        $first = $grid->first() ?? $filters['from'];
        $last = $grid->last() ?? $first;
        $step = count($grid->nights);
        $run = $grid->run;

        return [
            'ok' => true,
            'columns' => $columns,
            'rows' => $rows,
            'shown' => $grid->shown(),
            'caption' => sprintf(
                /* translators: 1: property name, 2: range of nights, 3: when prices were read */
                __('Nights of %1$s, %2$s, %3$s', 'ritmo-revenue'),
                $grid->property->name,
                NightDate::range($first, $last),
                $run === null ? __('prices from the PMS calendar (no run yet)', 'ritmo-revenue')
                    /* translators: %s: date and time */
                    : sprintf(__('prices as read at %s', 'ritmo-revenue'), NightDate::instant($run['started_at'], $grid->property->timezone))
            ),
            'no_run' => $run === null,
            'prev' => $page->url(['from' => Days::key(Days::add($first, -$step))] + self::keep($filters, ['from'])),
            'next' => $page->url(['from' => Days::key(Days::add($first, $step))] + self::keep($filters, ['from'])),
            'today' => $page->url(self::keep($filters, ['from'])),
            /* translators: %s: number of nights */
            'prev_label' => sprintf(__('← Previous %s nights', 'ritmo-revenue'), number_format_i18n($step)),
            /* translators: %s: number of nights */
            'next_label' => sprintf(__('Next %s nights →', 'ritmo-revenue'), number_format_i18n($step)),
            'room_pages' => self::roomPages($grid, $filters, $page),
            'empty_rooms' => $grid->rooms === [],
            'rooms_url' => Menu::url(Menu::SETTINGS, ['tab' => 'setup', 'step' => 5]),
        ];
    }

    /**
     * @param array<string, mixed> $filters
     *
     * @return array<string, mixed>
     */
    private static function filterView(array $filters, Page $page, Property $property): array
    {
        $rooms = ScreenServices::instance()->services()->roomTypes()->forProperty($property->id);
        $only = [
            'all' => __('every night', 'ritmo-revenue'),
            'would_change' => __('the last run would change', 'ritmo-revenue'),
            'refused' => __('refused by the safety checks', 'ritmo-revenue'),
            'frozen' => __('frozen', 'ritmo-revenue'),
            'with_order' => __('with an owner order', 'ritmo-revenue'),
            'with_alert' => __('with an open alert', 'ritmo-revenue'),
        ];
        foreach (NightFilter::STATUSES as $status) {
            $only['status:' . $status] = Labels::pace($status);
        }
        $show = [
            'price' => __('price now', 'ritmo-revenue'),
            'floor' => __('floor', 'ritmo-revenue'),
            'ceiling' => __('ceiling', 'ritmo-revenue'),
            'stay' => __('minimum stay', 'ritmo-revenue'),
            'occupancy' => __('units sold', 'ritmo-revenue'),
            'pace' => __('pace', 'ritmo-revenue'),
            'rule' => __('rule that decided', 'ritmo-revenue'),
        ];

        return [
            'action' => admin_url('admin.php'),
            'page' => Menu::NIGHTS,
            'property' => $property->id,
            'from' => Days::key($filters['from']),
            'nights' => $filters['nights'],
            'windows' => self::WINDOWS,
            'show' => $filters['show'],
            'show_options' => $show,
            'only' => $filters['only']->only,
            'only_options' => $only,
            'rooms' => array_map(static fn ($r): array => ['code' => $r->code, 'name' => $r->name, 'checked' => $filters['rooms'] === [] || in_array($r->code, $filters['rooms'], true)], $rooms),
            'clear' => $page->url(),
        ];
    }

    /**
     * The filters to keep in a link, without some of them.
     *
     * @param array<string, mixed> $filters
     * @param list<string>         $without
     *
     * @return array<string, string|int|list<string>>
     */
    private static function keep(array $filters, array $without = []): array
    {
        $keep = [
            'from' => Days::key($filters['from']),
            'nights' => $filters['nights'],
            'show' => $filters['show'],
            'only' => $filters['only']->only,
            'room_page' => $filters['room_page'],
        ];
        if ($filters['rooms'] !== []) {
            $keep['rooms'] = $filters['rooms'];
        }

        return array_diff_key(array_filter($keep, static fn ($v): bool => $v !== '' && $v !== 'all' && $v !== 'price' && $v !== 1), array_flip($without));
    }

    /**
     * @param array<string, mixed> $filters
     *
     * @return list<array{label: string, url: string, current: bool}>
     */
    private static function roomPages(NightGrid $grid, array $filters, Page $page): array
    {
        if ($grid->roomsInAll <= NightGridQuery::ROOMS_PER_PAGE) {
            return [];
        }
        $pages = [];
        for ($offset = 0, $number = 1; $offset < $grid->roomsInAll; $offset += NightGridQuery::ROOMS_PER_PAGE, ++$number) {
            $pages[] = [
                /* translators: 1: first room, 2: last room */
                'label' => sprintf(__('Rooms %1$s–%2$s', 'ritmo-revenue'), number_format_i18n($offset + 1), number_format_i18n(min($grid->roomsInAll, $offset + NightGridQuery::ROOMS_PER_PAGE))),
                'url' => $page->url(['room_page' => $number] + self::keep($filters, ['room_page'])),
                'current' => $offset === $grid->roomOffset,
            ];
        }

        return $pages;
    }

    /** The symbol that goes with the tint, so colour is never the only sign of the pace. */
    private static function mark(string $category): string
    {
        return match ($category) {
            'ahead' => '▲',
            'on_pace' => '●',
            'behind' => '▼',
            'very_behind' => '▼▼',
            'floor' => '■',
            default => '',
        };
    }

    private static function tone(string $category): string
    {
        return match ($category) {
            'ahead' => 'good',
            'behind', 'floor' => 'attention',
            'very_behind' => 'critical',
            'on_pace' => 'info',
            default => 'plain',
        };
    }
}
