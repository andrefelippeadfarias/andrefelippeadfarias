<?php

declare(strict_types=1);

namespace Ritmo\Admin\Screens;

use Closure;
use DateTimeImmutable;
use Ritmo\Admin\Components\Capability;
use Ritmo\Admin\Components\ErrorText;
use Ritmo\Admin\Components\Labels;
use Ritmo\Admin\Components\Menu;
use Ritmo\Admin\Components\Money;
use Ritmo\Admin\Components\NightDate;
use Ritmo\Admin\Components\Percent;
use Ritmo\Admin\Reports\RunLineText;
use Ritmo\Application\Dashboard\Todo\TodoItem;
use Ritmo\Domain\Model\Property;
use Throwable;

/**
 * Overview (admin spec 5.2): in ten seconds, is everything fine and what needs me now. Every card
 * is built on its own; a card that fails shows its error and the others still render.
 */
final class OverviewScreen
{
    private function __construct()
    {
    }

    public static function render(): void
    {
        $page = Page::open(Menu::OVERVIEW, __('Overview', 'ritmo-revenue'), Capability::VIEW);
        $property = $page->property;
        if ($property === null) {
            $page->render('overview', ['empty' => true, 'setup_url' => Menu::url(Menu::SETTINGS, ['tab' => 'setup'])]);

            return;
        }
        $query = ScreenServices::instance()->overview();
        $currency = $property->currency;
        $page->render('overview', [
            'empty' => false,
            'site' => count($page->properties) > 1 ? self::card(static fn (): array => self::site($query->forSite())) : null,
            'soon' => self::card(static fn (): array => self::soon($query->soon($property), $property)),
            'windows' => self::card(static fn (): array => self::windows($query->windows($property), $currency)),
            'pace' => self::card(static fn (): array => self::pace($query->pace($property), $property)),
            'needs' => self::card(static fn (): array => self::needs($query->needsYou($property), $property)),
            'last' => self::card(static fn (): array => self::lastRun($query->lastRun($property), $property)),
            'next' => self::card(static fn (): array => self::nextRuns($query->nextRuns($property), $property)),
            'can_run' => Capability::can(Capability::MANAGE_RULES),
            'property_id' => $property->id,
            'rest_url' => rest_url('ritmo/v1/'),
            'rest_nonce' => wp_create_nonce('wp_rest'),
        ]);
    }

    /**
     * @param Closure(): array<string, mixed> $build
     *
     * @return array<string, mixed>
     */
    private static function card(Closure $build): array
    {
        try {
            return ['ok' => true] + $build();
        } catch (Throwable $failure) {
            return ['ok' => false, 'error' => ErrorText::from($failure)];
        }
    }

    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return array<string, mixed>
     */
    private static function site(array $rows): array
    {
        return ['rows' => array_map(static fn (array $r): array => [
            'name' => $r['name'],
            'url' => Menu::url(Menu::OVERVIEW, ['property' => $r['id']]),
            'mode' => Labels::mode($r['mode']),
            /* translators: 1: free units tonight, 2: units in all */
            'tonight' => sprintf(__('%1$s of %2$s free', 'ritmo-revenue'), number_format_i18n($r['free_tonight']), number_format_i18n($r['units'])),
            'occupancy' => self::goalText($r['percent'], $r['goal']),
            'signal' => self::signal($r['signal']),
            /* translators: 1: critical alerts, 2: warnings */
            'alerts' => sprintf(__('%1$s critical, %2$s warnings', 'ritmo-revenue'), number_format_i18n($r['critical']), number_format_i18n($r['warning'])),
            'waiting' => number_format_i18n($r['waiting']),
            'last_run' => $r['last_run'] === null ? __('No run yet', 'ritmo-revenue') : Labels::runStatus($r['last_run']['status']),
        ], $rows)];
    }

    /**
     * @param array<string, mixed> $soon
     *
     * @return array<string, mixed>
     */
    private static function soon(array $soon, Property $property): array
    {
        $labels = [__('Tonight', 'ritmo-revenue'), __('Tomorrow', 'ritmo-revenue')];
        $columns = [];
        foreach ($soon['nights'] as $index => $night) {
            $columns[] = ($labels[$index] ?? NightDate::weekday($night)) . ' · ' . NightDate::day($night);
        }
        $rows = [];
        foreach ($soon['rooms'] as $room) {
            $cells = [];
            foreach ($room['nights'] as $night) {
                $state = $night['free'] <= 0 && !$night['closed'] ? __('sold out', 'ritmo-revenue')
                    : ($night['closed'] ? __('closed', 'ritmo-revenue')
                    /* translators: %s: free units */
                    : sprintf(_n('%s free', '%s free', $night['free'], 'ritmo-revenue'), number_format_i18n($night['free'])));
                $cells[] = ['state' => $state, 'price' => $night['price'] === null ? '—' : Money::format($night['price'], $property->currency)];
            }
            $rows[] = ['room' => $room['name'], 'cells' => $cells];
        }

        return [
            'columns' => $columns,
            'rows' => $rows,
            'caption' => $soon['captured_at'] === null ? __('No calendar read yet: it is read by the first run.', 'ritmo-revenue')
                /* translators: %s: date and time the calendar was read */
                : sprintf(__('Tonight and the next nights, as read at %s', 'ritmo-revenue'), NightDate::instant($soon['captured_at'], $property->timezone)),
        ];
    }

    /**
     * @param list<array<string, mixed>> $windows
     *
     * @return array<string, mixed>
     */
    private static function windows(array $windows, string $currency): array
    {
        return ['cards' => array_map(static fn (array $w): array => [
            /* translators: %s: number of days */
            'title' => sprintf(__('Next %s days', 'ritmo-revenue'), number_format_i18n($w['days'])),
            'hook' => $w['days'] === 7 ? 'card-next-7' : 'card-next-30',
            'occupancy' => sprintf(
                /* translators: 1: occupancy percent, 2: room nights sold, 3: room nights in all */
                __('%1$s · %2$s of %3$s room nights sold', 'ritmo-revenue'),
                Percent::share($w['percent']),
                number_format_i18n($w['sold']),
                number_format_i18n($w['capacity'])
            ),
            /* translators: %s: goal percent */
            'goal' => sprintf(__('goal %s, from your rules', 'ritmo-revenue'), Percent::share($w['goal'])),
            'signal' => self::signal($w['signal']),
            'revenue' => sprintf(
                /* translators: 1: amount, 2: number of bookings */
                _n('%1$s on the books, from %2$s booking', '%1$s on the books, from %2$s bookings', $w['bookings'], 'ritmo-revenue'),
                Money::format($w['revenue'], $currency),
                number_format_i18n($w['bookings'])
            ),
            'adr' => $w['room_nights'] > 0
                /* translators: %s: average daily rate */
                ? sprintf(__('average daily rate %s (revenue ÷ nights sold)', 'ritmo-revenue'), Money::format($w['revenue'] / $w['room_nights'], $currency))
                : __('no night sold yet', 'ritmo-revenue'),
            'rooms' => array_map(static fn (array $r): array => [
                'name' => $r['name'],
                /* translators: 1: sold, 2: available, 3: percent */
                'text' => sprintf(__('%1$s of %2$s sold (%3$s)', 'ritmo-revenue'), number_format_i18n($r['sold']), number_format_i18n($r['capacity']), Percent::share($r['percent'])),
            ], $w['rooms']),
        ], $windows)];
    }

    /**
     * @param array{run_id: int|null, counts: array<string, int>, total: int} $pace
     *
     * @return array<string, mixed>
     */
    private static function pace(array $pace, Property $property): array
    {
        $items = [];
        foreach ($pace['counts'] as $status => $count) {
            if ($count === 0 && $status === 'none') {
                continue;
            }
            $items[] = [
                'text' => number_format_i18n($count) . ' ' . Labels::pace($status === 'none' ? null : $status),
                'symbol' => Labels::paceSymbol($status),
                'url' => Menu::url(Menu::NIGHTS, ['property' => $property->id, 'only' => 'status:' . $status, 'nights' => 60]),
                'tone' => self::paceTone($status),
            ];
        }

        return ['items' => $items, 'total' => $pace['total'], 'empty' => $pace['run_id'] === null];
    }

    /**
     * @param array<string, mixed> $needs
     *
     * @return array<string, mixed>
     */
    private static function needs(array $needs, Property $property): array
    {
        return [
            'waiting' => $needs['waiting_requests'] === 0 ? null : sprintf(
                /* translators: 1: nights waiting, 2: time the plan expires */
                _n('%1$s night waits for your approval, valid until %2$s', '%1$s nights wait for your approval, valid until %2$s', $needs['waiting_entries'], 'ritmo-revenue'),
                number_format_i18n($needs['waiting_entries']),
                $needs['expires'] === null ? '—' : NightDate::time($needs['expires'], $property->timezone)
            ),
            'approvals_url' => Menu::url(Menu::APPROVALS, ['property' => $property->id]),
            'can_approve' => Capability::can(Capability::APPROVE),
            'alerts' => array_map(static fn (array $a): array => [
                'title' => AlertText::title($a['code'], $a['context']),
                'when' => NightDate::instant($a['last_seen'], $property->timezone),
                'url' => Menu::url(Menu::ALERTS, ['property' => $property->id, 'alert' => $a['id']]),
            ], $needs['alerts']),
            'todos' => array_map(static fn (TodoItem $t): array => [
                'title' => TodoText::title($t),
                'id' => (int) $t->id,
            ], $needs['todos']),
            'todo_url' => Menu::url(Menu::TO_DO, ['property' => $property->id]),
            'can_close' => Capability::can(Capability::MANAGE_RULES),
            'property_id' => $property->id,
        ];
    }

    /**
     * @param array<string, mixed> $last
     *
     * @return array<string, mixed>
     */
    private static function lastRun(array $last, Property $property): array
    {
        $run = $last['run'];
        if ($run === null) {
            return ['run' => null];
        }
        $seconds = $run['finished_at'] === null ? null : $run['finished_at']->getTimestamp() - $run['started_at']->getTimestamp();
        $did = match (true) {
            $last['writes_sent'] !== null && $last['writes_sent'] > 0 => sprintf(
                /* translators: %s: number of changes */
                _n('wrote %s change', 'wrote %s changes', $last['writes_sent'], 'ritmo-revenue'),
                number_format_i18n($last['writes_sent'])
            ),
            $last['would_write'] !== null => sprintf(
                /* translators: %s: number of nights */
                _n('would write %s night (shadow)', 'would write %s nights (shadow)', $last['would_write'], 'ritmo-revenue'),
                number_format_i18n($last['would_write'])
            ),
            default => __('changed nothing', 'ritmo-revenue'),
        };

        return [
            'run' => [
                'title' => sprintf(
                    /* translators: 1: kind of run, 2: time */
                    __('%1$s at %2$s', 'ritmo-revenue'),
                    Labels::runKind($run['kind']),
                    NightDate::instant($run['started_at'], $property->timezone)
                ) . ($seconds === null ? '' : ' · ' . sprintf(
                    /* translators: %s: seconds */
                    _n('took %s second', 'took %s seconds', $seconds, 'ritmo-revenue'),
                    number_format_i18n($seconds)
                )),
                'status' => Labels::runStatus($run['status']),
                'did' => $did,
                'lines' => array_slice(RunLineText::ofSummary(is_array($run['summary'] ?? null) ? $run['summary'] : [])['lines'], 0, 8),
                'detail_url' => Menu::url(Menu::SETTINGS, ['tab' => 'diagnostics', 'run' => $run['id'], 'property' => $property->id]),
                'nights_url' => Menu::url(Menu::NIGHTS, ['property' => $property->id, 'only' => 'would_change']),
                'shadow' => $last['would_write'] !== null,
            ],
        ];
    }

    /**
     * @param array{full: DateTimeImmutable, light_left: list<DateTimeImmutable>} $next
     *
     * @return array<string, mixed>
     */
    private static function nextRuns(array $next, Property $property): array
    {
        return [
            'mode' => Labels::mode($property->mode->value),
            'sentence' => Labels::modeSentence($property->mode->value),
            /* translators: %s: date and time */
            'full' => sprintf(__('Next full run: %s', 'ritmo-revenue'), NightDate::instant($next['full'], $property->timezone)),
            'light' => $next['light_left'] === [] ? __('No light run left today.', 'ritmo-revenue') : sprintf(
                /* translators: %s: times of the light runs */
                __('Light runs left today: %s', 'ritmo-revenue'),
                implode(', ', array_map(static fn (DateTimeImmutable $t): string => NightDate::time($t, $property->timezone), $next['light_left']))
            ),
            'operations_url' => Menu::url(Menu::OPERATIONS, ['property' => $property->id]),
        ];
    }

    private static function goalText(?float $percent, ?float $goal): string
    {
        /* translators: 1: occupancy, 2: goal */
        return sprintf(__('%1$s (goal %2$s)', 'ritmo-revenue'), Percent::share($percent), Percent::share($goal));
    }

    /**
     * @return array{text: string, tone: string}
     */
    private static function signal(string $signal): array
    {
        return match ($signal) {
            'green' => ['text' => __('good', 'ritmo-revenue'), 'tone' => 'good'],
            'amber' => ['text' => __('close to the goal', 'ritmo-revenue'), 'tone' => 'attention'],
            'red' => ['text' => __('below the goal', 'ritmo-revenue'), 'tone' => 'critical'],
            default => ['text' => __('not measured yet', 'ritmo-revenue'), 'tone' => 'neutral'],
        };
    }

    private static function paceTone(string $status): string
    {
        return match ($status) {
            'ahead' => 'good',
            'behind', 'floor' => 'attention',
            'very_behind' => 'critical',
            default => 'neutral',
        };
    }
}
