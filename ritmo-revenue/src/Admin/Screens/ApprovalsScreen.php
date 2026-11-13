<?php

declare(strict_types=1);

namespace Ritmo\Admin\Screens;

use DateTimeImmutable;
use DateTimeZone;
use Ritmo\Admin\Components\Capability;
use Ritmo\Admin\Components\ErrorText;
use Ritmo\Admin\Components\Input;
use Ritmo\Admin\Components\Labels;
use Ritmo\Admin\Components\Menu;
use Ritmo\Admin\Components\NightDate;
use Ritmo\Admin\Components\PriceText;
use Ritmo\Admin\ReasonText;
use Ritmo\Application\Grid\Approvals\EntryRow;
use Ritmo\Application\Grid\Approvals\Impact;
use Ritmo\Application\Grid\Approvals\RequestView;
use Ritmo\Application\Grid\Approvals\Segment;
use Ritmo\Domain\Model\Property;
use Ritmo\Domain\Support\Days;
use Ritmo\Domain\Support\Num;
use Throwable;

/**
 * Approvals (admin spec 5.4): every request waiting, in money and in words, grouped by room and
 * by segments of nights; the selection in three levels (room, segment, night; the last one works
 * without JavaScript); listing changes apart; what is being written; the history.
 */
final class ApprovalsScreen
{
    private function __construct()
    {
    }

    public static function render(): void
    {
        $tabs = array_values(array_filter(Menu::entries(), static fn (array $e): bool => $e['slug'] === Menu::APPROVALS))[0]['tabs'];
        $page = Page::open(Menu::APPROVALS, __('Approvals', 'ritmo-revenue'), Capability::VIEW, $tabs);
        $property = $page->property;
        if ($property === null) {
            $page->render('approvals', ['empty' => true, 'empty_text' => __('There is no property yet.', 'ritmo-revenue'), 'requests' => [], 'written' => [], 'history' => null]);

            return;
        }
        $board = ScreenServices::instance()->approvals();
        try {
            if ($page->tab === 'history') {
                $current = Input::query()->int('paged', 1, 1, 1000);
                $history = $board->history($property->id, $current);
                $page->render('approvals', ['empty' => false, 'requests' => [], 'written' => [], 'history' => self::history($history, $property, $page, $current)]);

                return;
            }
            $waiting = array_map(static fn (RequestView $r): array => self::request($r, $property), $board->waiting($property->id));
            $followed = $board->beingWritten($property->id);
            $opened = Input::query()->int('request', 0, 0);
            if ($opened > 0 && array_filter([...$followed, ...$board->waiting($property->id)], static fn (RequestView $r): bool => $r->id === $opened) === []) {
                $followed[] = $board->find($property->id, $opened);
            }
            $written = array_map(static fn (RequestView $r): array => self::progress($r, $property), $followed);
        } catch (Throwable $failure) {
            $page->render('approvals', ['empty' => false, 'error' => ErrorText::from($failure), 'requests' => [], 'written' => [], 'history' => null]);

            return;
        }
        $page->render('approvals', [
            'empty' => $waiting === [],
            'empty_text' => self::emptyText($property),
            'requests' => $waiting,
            'written' => $written,
            'history' => null,
            'rest_url' => rest_url('ritmo/v1/'),
            'rest_nonce' => wp_create_nonce('wp_rest'),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private static function request(RequestView $request, Property $property): array
    {
        $currency = $property->currency;
        $canApprove = Capability::can(Capability::APPROVE);
        $rooms = [];
        $names = [];
        $bases = [];
        foreach (ScreenServices::instance()->services()->roomTypes()->forProperty($property->id) as $room) {
            $names[$room->code] = $room->name;
            $bases[$room->code] = $room->base;
        }
        foreach ($request->rooms as $code => $segments) {
            $rooms[] = [
                'code' => $code,
                'name' => $names[$code] ?? $code,
                /* translators: %s: number of nights */
                'count' => sprintf(_n('%s night', '%s nights', array_sum(array_map(static fn (Segment $s): int => $s->nights(), $segments)), 'ritmo-revenue'), number_format_i18n(array_sum(array_map(static fn (Segment $s): int => $s->nights(), $segments)))),
                'open' => count($segments) <= 10,
                'segments' => array_map(static fn (Segment $s): array => self::segment($s, $currency, $names, $bases), $segments),
            ];
        }

        return [
            'id' => $request->id,
            'kind' => $request->kind,
            'title' => self::title($request, $property, count($request->rooms)),
            'expiry' => self::expiry($request, $property),
            'expired' => $request->expired,
            'impact' => self::impact($request->impact, $currency),
            'rooms' => $rooms,
            'listing' => array_map(static fn (EntryRow $e): array => [
                'key' => $e->key,
                'field' => md5($e->key),
                'label' => ($names[$e->room] ?? $e->room) . ' · ' . Labels::field($e->subject),
                'price' => PriceText::change($e->before(), $e->after(), $currency),
                'type' => ApprovalActions::plain($e->after()),
            ], $request->listing),
            'pairs' => array_map(static fn (array $p): string => sprintf(
                /* translators: 1: rooms, 2: name of the relation, 3: night */
                __('%1$s are linked by the relation %2$s on %3$s. Approving only one of them may make the whole approval be refused when it is written.', 'ritmo-revenue'),
                implode(' / ', array_map(static fn (string $r): string => $names[$r] ?? $r, $p['rooms'])),
                $p['relation'],
                NightDate::day(Days::date($p['night']))
            ), $request->pairs),
            'can_approve' => $canApprove && !$request->expired,
            'can_listing' => $canApprove && Capability::can(Capability::MANAGE_SAFETY),
            'entries' => $request->entries,
            'property_id' => $property->id,
        ];
    }

    /**
     * @param array<string, string> $names
     * @param array<string, float>  $bases
     *
     * @return array<string, mixed>
     */
    private static function segment(Segment $segment, string $currency, array $names, array $bases): array
    {
        $before = $segment->beforeRange();
        $after = $segment->afterRange();
        $median = $segment->medianChange();

        return [
            'keys' => $segment->keys(),
            'range' => NightDate::range($segment->first(), $segment->last()),
            /* translators: %s: number of nights */
            'nights' => sprintf(_n('%s night', '%s nights', $segment->nights(), 'ritmo-revenue'), number_format_i18n($segment->nights())),
            'reason' => $segment->direction() === EntryRow::REWRITE ? __('Rule deleted and written again', 'ritmo-revenue') : NightText::reason($segment->reason()),
            'change' => self::range($before, $currency) . ' → ' . self::range($after, $currency),
            'median' => $median === null ? '' : sprintf(
                /* translators: %s: change in percent */
                __('median %s', 'ritmo-revenue'),
                self::signed($median)
            ),
            'direction' => $segment->direction(),
            'warnings' => array_map(static fn (string $w): array => ['code' => $w, 'text' => WarningText::short($w, $segment->entries[0])], $segment->warnings()),
            'held' => $segment->entries[0]->held,
            'rows' => array_map(static fn (EntryRow $e): array => self::night($e, $currency, $names, $bases[$e->room] ?? 0.0), $segment->entries),
            'before' => $before[0] ?? 0.0,
            'after' => $after[0] ?? 0.0,
        ];
    }

    /**
     * @param array<string, string> $names
     *
     * @return array<string, mixed>
     */
    private static function night(EntryRow $entry, string $currency, array $names, float $base): array
    {
        $changes = [];
        if ($entry->kind === 'deletion') {
            $changes[] = ['field' => __('date rule removed before it is written again', 'ritmo-revenue'), 'price' => null];
        }
        if ($entry->floorAfter !== null) {
            $changes[] = ['field' => __('floor', 'ritmo-revenue'), 'price' => self::moving($entry->floorBefore, $entry->floorAfter, $entry->floorPercent, $base, $currency)];
        }
        if ($entry->ceilingAfter !== null) {
            $changes[] = ['field' => __('ceiling', 'ritmo-revenue'), 'price' => self::moving($entry->ceilingBefore, $entry->ceilingAfter, $entry->ceilingPercent, $base, $currency)];
        }
        if (isset($entry->fields['min_stay'])) {
            /* translators: %s: nights */
            $changes[] = ['field' => sprintf(_n('minimum stay %s night', 'minimum stay %s nights', (int) $entry->fields['min_stay'], 'ritmo-revenue'), number_format_i18n((int) $entry->fields['min_stay'])), 'price' => null];
        }
        foreach (['check_in_mask' => __('arrival days change', 'ritmo-revenue'), 'check_out_mask' => __('departure days change', 'ritmo-revenue')] as $field => $text) {
            if (isset($entry->fields[$field])) {
                $changes[] = ['field' => $text, 'price' => null];
            }
        }

        return [
            'key' => $entry->key,
            'group' => $entry->group,
            'date' => $entry->night === null ? '' : NightDate::short($entry->night),
            'room' => $names[$entry->room] ?? $entry->room,
            'changes' => $changes,
            'reason' => ReasonText::describe($entry->trace),
            'pace' => $entry->paceStatus === null ? '' : Labels::pace($entry->paceStatus),
            'warnings' => array_map(static fn (string $w): array => ['code' => $w, 'text' => WarningText::long($w, $entry)], $entry->warnings),
            'held' => $entry->held,
            'direction' => $entry->direction,
            'kind' => $entry->kind,
            'before' => $entry->before() ?? 0.0,
            'after' => $entry->after() ?? 0.0,
        ];
    }

    /**
     * @return array{text: string, note: string, sr: string}
     */
    private static function moving(?float $before, float $after, ?int $percent, float $roomBase, string $currency): array
    {
        $change = PriceText::change($before, $after, $currency);
        if ($percent === null || $roomBase <= 0) {
            return $change;
        }
        $base = PriceText::withBase($after, $percent, $roomBase, $currency);
        $note = trim($change['note'] . ($change['note'] === '' ? '' : '; ') . $base['note']);

        return ['text' => $change['text'], 'note' => $note, 'sr' => $change['sr'] . ' (' . $note . ')'];
    }

    /**
     * @return array<string, mixed>
     */
    private static function progress(RequestView $request, Property $property): array
    {
        return [
            'id' => $request->id,
            'title' => self::title($request, $property, count($request->rooms)),
            'status' => Labels::approvalStatus($request->status),
            'final' => $request->status !== 'approved',
            'decided' => $request->decidedBy === null ? '' : sprintf(
                /* translators: 1: name, 2: date and time */
                __('Approved by %1$s at %2$s', 'ritmo-revenue'),
                PeopleNames::name($request->decidedBy),
                $request->decidedAt === null ? '—' : NightDate::instant($request->decidedAt, $property->timezone)
            ),
        ];
    }

    /**
     * @param array{rows: list<RequestView>, total_known: int} $history
     *
     * @return array<string, mixed>
     */
    private static function history(array $history, Property $property, Page $page, int $current): array
    {
        return [
            'rows' => array_map(static fn (RequestView $r): array => [
                'created' => NightDate::instant($r->createdAt, $property->timezone),
                'kind' => Labels::approvalKind($r->kind),
                /* translators: %s: number of entries */
                'nights' => sprintf(_n('%s entry', '%s entries', $r->entries, 'ritmo-revenue'), number_format_i18n($r->entries)),
                'status' => Labels::approvalStatus($r->expired ? 'expired' : $r->status),
                'by' => $r->decidedBy === null ? '—' : PeopleNames::name($r->decidedBy),
                'at' => $r->decidedAt === null ? '—' : NightDate::instant($r->decidedAt, $property->timezone),
                'note' => $r->note,
            ], $history['rows']),
            'previous' => $current > 1 ? $page->url(['paged' => $current - 1]) : '',
            'next' => $history['total_known'] > $current * 20 ? $page->url(['paged' => $current + 1]) : '',
        ];
    }

    private static function title(RequestView $request, Property $property, int $rooms): string
    {
        $kind = match ($request->kind) {
            'undo' => __('Undo', 'ritmo-revenue'),
            'listing' => __('Listing change', 'ritmo-revenue'),
            default => __('Plan', 'ritmo-revenue'),
        };

        return sprintf(
            /* translators: 1: Plan, Undo or Listing change, 2: date, 3: property, 4: entries, 5: rooms */
            _n('%1$s of %2$s for %3$s — %4$s entries in %5$s room', '%1$s of %2$s for %3$s — %4$s entries in %5$s rooms', max(1, $rooms), 'ritmo-revenue'),
            $kind,
            NightDate::short($request->planDay),
            $property->name,
            number_format_i18n($request->entries),
            number_format_i18n($rooms)
        );
    }

    private static function expiry(RequestView $request, Property $property): string
    {
        if ($request->expired) {
            /* translators: %s: time */
            return sprintf(__('This plan expired at %s: the next run proposes a new one.', 'ritmo-revenue'), NightDate::time($request->expiresAt, $property->timezone));
        }
        $midnight = new DateTimeImmutable(Days::key(Days::add($request->planDay, 1)) . ' 00:00:00', new DateTimeZone($property->timezone));
        $why = $request->expiresAt < $midnight ? __('the calendar it was built on gets too old then', 'ritmo-revenue') : __('midnight of the property', 'ritmo-revenue');

        /* translators: 1: time, 2: why it expires then */
        return sprintf(__('valid until %1$s (%2$s)', 'ritmo-revenue'), NightDate::time($request->expiresAt, $property->timezone), $why);
    }

    /**
     * @return list<string>
     */
    private static function impact(Impact $impact, string $currency): array
    {
        $lines = [];
        if ($impact->up > 0) {
            $lines[] = sprintf(
                /* translators: 1: nights, 2: median change, 3: largest rise */
                _n('%1$s night up (median %2$s, largest %3$s)', '%1$s nights up (median %2$s, largest %3$s)', $impact->up, 'ritmo-revenue'),
                number_format_i18n($impact->up),
                self::signed((float) $impact->medianUp),
                $impact->largestRise === null ? '—' : '+' . PriceText::amount($impact->largestRise['amount'], $currency)['text'] . ' · ' . NightDate::day($impact->largestRise['night'])
            );
        }
        if ($impact->down > 0) {
            /* translators: 1: nights, 2: median change */
            $lines[] = sprintf(_n('%1$s night down (median %2$s)', '%1$s nights down (median %2$s)', $impact->down, 'ritmo-revenue'), number_format_i18n($impact->down), self::signed((float) $impact->medianDown));
        }
        /* translators: %s: nights */
        $lines[] = sprintf(_n('%s tonight or tomorrow', '%s tonight or tomorrow', $impact->soon, 'ritmo-revenue'), number_format_i18n($impact->soon));
        /* translators: %s: nights */
        $lines[] = sprintf(_n('%s on a holiday', '%s on holidays', $impact->holidays, 'ritmo-revenue'), number_format_i18n($impact->holidays));
        if ($impact->rewrites > 0) {
            /* translators: %s: number */
            $lines[] = sprintf(_n('%s rule deleted and written again', '%s rules deleted and written again', $impact->rewrites, 'ritmo-revenue'), number_format_i18n($impact->rewrites));
        }
        /* translators: %s: number */
        $lines[] = sprintf(_n('%s listing change', '%s listing changes', $impact->listing, 'ritmo-revenue'), number_format_i18n($impact->listing));

        return $lines;
    }

    private static function emptyText(Property $property): string
    {
        return match ($property->mode->value) {
            'shadow' => __('This property is in shadow mode: plans are only shown in Nights.', 'ritmo-revenue'),
            'auto' => __('Autonomous: only listing changes and repairs wait here.', 'ritmo-revenue'),
            default => __('The next full run proposes the next plan.', 'ritmo-revenue'),
        };
    }

    /**
     * @param array{0: float, 1: float}|null $range
     */
    private static function range(?array $range, string $currency): string
    {
        if ($range === null) {
            return '—';
        }
        $low = PriceText::amount($range[0], $currency)['text'];

        return abs($range[1] - $range[0]) < 0.005 ? $low : $low . '–' . PriceText::amount($range[1], $currency)['text'];
    }

    private static function signed(float $percent): string
    {
        $value = Num::roundHalfEven($percent, 1);

        return ($value > 0 ? '+' : ($value < 0 ? "\u{2212}" : '')) . number_format_i18n(abs($value), 1) . '%';
    }
}
