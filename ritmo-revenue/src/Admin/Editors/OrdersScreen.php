<?php

declare(strict_types=1);

namespace Ritmo\Admin\Editors;

use DateTimeImmutable;
use DateTimeZone;
use Ritmo\Admin\Components\Capability;
use Ritmo\Admin\Components\Input;
use Ritmo\Admin\Components\Menu;
use Ritmo\Admin\Components\NightDate;
use Ritmo\Admin\Components\Tab;
use Ritmo\Application\Rules\Orders\OrderForm;
use Ritmo\Domain\Orders\OrderRelease;
use Ritmo\Domain\Orders\OrderType;
use Ritmo\Domain\Orders\OwnerOrder;
use Ritmo\Domain\Support\Days;
use Ritmo\Domain\Write\ThirdParty\DetectedFreeze;

/**
 * The Orders screen (spec 05, 5.8): orders in force, frozen nights, ended orders, and the form of
 * a new order (or of a copy: orders are never edited). An order applies from the next run.
 */
final class OrdersScreen
{
    private function __construct()
    {
    }

    /**
     * @return list<Tab>
     */
    public static function tabs(): array
    {
        $v = Capability::VIEW;

        return [
            new Tab('active', __('In force', 'ritmo-revenue'), $v),
            new Tab('freezes', __('Frozen nights', 'ritmo-revenue'), $v),
            new Tab('ended', __('Ended', 'ritmo-revenue'), $v),
            new Tab('new', __('New order', 'ritmo-revenue'), Capability::MANAGE_RULES),
        ];
    }

    public static function render(): void
    {
        $page = EditorPage::open(Menu::ORDERS, __('Orders', 'ritmo-revenue'), Capability::VIEW, self::tabs());
        if ($page->property === null) {
            $page->render('admin/editors/orders', ['rows' => [], 'detected' => [], 'form' => null, 'no_property' => true]);

            return;
        }
        $services = EditorServices::instance();
        $today = $services->services()->clock()->today($page->property->timezone);
        $board = $services->orderBoard();
        $names = $page->roomNames();
        $view = ['no_property' => false, 'rows' => [], 'detected' => [], 'form' => null, 'may_rules' => Capability::can(Capability::MANAGE_RULES), 'may_approve' => Capability::can(Capability::APPROVE)];
        $query = Input::query();

        match ($page->tab) {
            'freezes' => $view = ['rows' => self::rows($board->freezes($page->property->id, $today)['manual'], $page), 'detected' => self::freezes($board->freezes($page->property->id, $today)['detected'], $names)] + $view,
            'ended' => $view = ['rows' => self::rows($board->ended($page->property->id, $today), $page)] + $view,
            'new' => $view = ['form' => self::form($page, $query->choice('type', OrderForm::TYPES, ''), $query->int('copy', 0, 0))] + $view,
            default => $view = ['rows' => self::rows($board->active($page->property->id, $today), $page)] + $view,
        };
        $page->render('admin/editors/orders', $view);
    }

    /**
     * @param list<array{id: int, order: OwnerOrder|null, problem: string, created_by: int, created_at: DateTimeImmutable, ended_at: DateTimeImmutable|null}> $rows
     *
     * @return list<array<string, mixed>>
     */
    private static function rows(array $rows, EditorPage $page): array
    {
        $names = $page->roomNames();
        $format = get_option('date_format') . ' ' . get_option('time_format');

        return array_map(static function (array $row) use ($names, $page, $format): array {
            $order = $row['order'];

            return [
                'id' => $row['id'],
                'readable' => $order !== null,
                'name' => $order === null ? __('An order that cannot be read', 'ritmo-revenue') : ($order->note !== '' ? strtok($order->note, "\n") : OrderText::type($order->type)),
                'type' => $order === null ? '' : OrderText::type($order->type),
                'rooms' => $order === null ? '' : OrderText::rooms($order, $names),
                'nights' => $order === null ? '' : self::nights($order),
                'does' => $order === null ? __('The runs ignore it. End it and create it again.', 'ritmo-revenue') : OrderText::describe($order, $names, $page->currency()),
                'releases' => $order === null ? [] : array_map(OrderText::release(...), $order->releases),
                'by' => VersionsView::person($row['created_by']),
                'at' => wp_date($format, $row['created_at']->getTimestamp()),
                'ended' => $row['ended_at'] === null ? '' : wp_date($format, $row['ended_at']->getTimestamp()),
                'type_code' => $order?->type->value ?? '',
            ];
        }, $rows);
    }

    private static function nights(OwnerOrder $order): string
    {
        $utc = new DateTimeZone('UTC');
        if ($order->nights !== []) {
            /* translators: 1: number of nights, 2: first night, 3: last night */
            return sprintf(_n('%1$d night between %2$s and %3$s', '%1$d nights between %2$s and %3$s', count($order->nights), 'ritmo-revenue'), count($order->nights), wp_date('j M Y', $order->from->getTimestamp(), $utc), wp_date('j M Y', $order->to->getTimestamp(), $utc));
        }
        $count = Days::diff($order->from, $order->to) + 1;

        /* translators: 1: first night, 2: last night, 3: number of nights */
        return sprintf(_n('%1$s – %2$s · %3$d night', '%1$s – %2$s · %3$d nights', $count, 'ritmo-revenue'), wp_date('j M Y', $order->from->getTimestamp(), $utc), wp_date('j M Y', $order->to->getTimestamp(), $utc), $count);
    }

    /**
     * @param list<DetectedFreeze>    $freezes
     * @param array<string, string> $names
     *
     * @return list<array<string, mixed>>
     */
    private static function freezes(array $freezes, array $names): array
    {
        $utc = new DateTimeZone('UTC');

        return array_map(static fn (DetectedFreeze $f): array => [
            'id' => (int) $f->id,
            'room' => $names[$f->roomCode] ?? $f->roomCode,
            'nights' => implode(', ', array_map(NightDate::short(...), $f->nights)),
            'fields' => implode(', ', array_map(self::changedField(...), $f->fields)),
            'until' => $f->frozenUntil === null ? __('until the night passes', 'ritmo-revenue') : wp_date(get_option('date_format'), $f->frozenUntil->getTimestamp()),
        ], $freezes);
    }

    private static function changedField(string $field): string
    {
        return match (true) {
            str_contains($field, 'min_stay') => __('minimum stay', 'ritmo-revenue'),
            str_contains($field, 'max_stay') => __('maximum stay', 'ritmo-revenue'),
            str_contains($field, 'price'), str_contains($field, 'floor'), str_contains($field, 'ceiling') => __('price', 'ritmo-revenue'),
            str_contains($field, 'check') || str_contains($field, 'arrival') || str_contains($field, 'departure') => __('arrival or departure', 'ritmo-revenue'),
            default => __('availability', 'ritmo-revenue'),
        };
    }

    /**
     * @return array<string, mixed>
     */
    private static function form(EditorPage $page, string $type, int $copyOf): array
    {
        $copy = null;
        if ($copyOf > 0 && $page->property !== null) {
            foreach (EditorServices::instance()->orderBoard()->active($page->property->id, Days::date('2000-01-01')) as $row) {
                if ($row['id'] === $copyOf && $row['order'] !== null) {
                    $copy = $row['order'];
                }
            }
        }
        $type = $copy !== null ? $copy->type->value : $type;
        $releases = array_map(static fn (OrderRelease $r): array => ['value' => $r->value, 'text' => OrderText::release($r), 'safety' => in_array($r->value, OrderForm::SAFETY_RELEASES, true)], OrderRelease::cases());

        return [
            'type' => $type,
            'types' => array_map(static fn (string $t): array => ['value' => $t, 'label' => OrderText::type(OrderType::from($t))], OrderForm::TYPES),
            'rooms' => $page->roomNames(),
            'copy' => $copy === null ? null : ['id' => $copyOf, 'rooms' => $copy->rooms, 'from' => Days::key($copy->from), 'to' => Days::key($copy->to), 'nights' => $copy->nights, 'floors' => $copy->floors, 'percentile' => $copy->percentile, 'releases' => array_map(static fn (OrderRelease $r): string => $r->value, $copy->releases), 'note' => $copy->note],
            'releases' => array_values(array_filter($releases, static fn (array $r): bool => in_array($r['value'], ['fixed_price', 'below_standard_floor', 'above_weekly_rise_limit', 'stay', 'orphan_stay', 'arrival_departure', 'discount_band', 'listing'], true))),
            'may_safety' => Capability::can(Capability::MANAGE_SAFETY),
            'property_id' => $page->property?->id ?? '',
            'percentile' => (string) (int) EditorServices::instance()->drafts()->inForce()['orders']['defaults']['target_percentile'],
        ];
    }
}
