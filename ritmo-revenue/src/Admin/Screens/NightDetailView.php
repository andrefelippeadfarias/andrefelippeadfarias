<?php

declare(strict_types=1);

namespace Ritmo\Admin\Screens;

use DateTimeImmutable;
use Ritmo\Admin\Components\Capability;
use Ritmo\Admin\Components\ErrorText;
use Ritmo\Admin\Components\Labels;
use Ritmo\Admin\Components\Menu;
use Ritmo\Admin\Components\NightDate;
use Ritmo\Admin\Components\PriceText;
use Ritmo\Admin\ReasonText;
use Ritmo\Application\Dashboard\Changes\ChangeGroups;
use Ritmo\Application\Grid\NightDetail;
use Ritmo\Domain\Model\Property;
use Ritmo\Domain\Support\Days;
use Ritmo\Domain\Support\Num;
use Ritmo\Domain\Write\Change\ChangeRecord;
use Throwable;

/**
 * The night detail of the Nights screen in words (admin spec 5.3): occupancy, prices (money first,
 * percent of the base in words), stay, why, freezes and history. Bookings only by PMS id and
 * channel.
 */
final class NightDetailView
{
    private function __construct()
    {
    }

    /**
     * @return array<string, mixed>
     */
    public static function build(Property $property, string $room, DateTimeImmutable $night, Page $page): array
    {
        try {
            $detail = ScreenServices::instance()->nightDetail()->detail($property->id, $room, $night);
        } catch (Throwable $failure) {
            return ['ok' => false, 'error' => ErrorText::from($failure), 'close' => $page->url()];
        }

        return ['ok' => true] + self::view($detail, $property, $page);
    }

    /**
     * @return array<string, mixed>
     */
    private static function view(NightDetail $detail, Property $property, Page $page): array
    {
        $cell = $detail->cell;
        $planned = $cell->planned;
        $base = $detail->room->base;
        $currency = $property->currency;
        $key = Days::key($cell->night);
        $absolute = Num::roundHalfEven($detail->room->referenceMin * (float) ScreenServices::instance()->wizard()->rules()->get('floors.fraction_of_reference_min'), 2);

        return [
            'title' => $detail->room->name . ' · ' . NightDate::long($cell->night),
            'kind' => trim(Labels::nightType($cell->nightType) . ($cell->period === '' ? '' : ' · ' . $cell->period)),
            /* translators: %s: number of days */
            'days_out' => sprintf(_n('%s day out', '%s days out', $cell->daysOut, 'ritmo-revenue'), number_format_i18n($cell->daysOut)),
            'previous' => $page->url(['room' => $detail->room->code, 'night' => Days::key(Days::add($cell->night, -1))]) . '#ritmo-night-detail',
            'next' => $page->url(['room' => $detail->room->code, 'night' => Days::key(Days::add($cell->night, 1))]) . '#ritmo-night-detail',
            'close' => $page->url(),
            /* translators: 1: units sold, 2: units of the room */
            'occupancy' => sprintf(__('%1$s of %2$s sold', 'ritmo-revenue'), number_format_i18n((int) $cell->unitsSold), number_format_i18n($cell->unitsTotal)),
            'closed' => $cell->closedWithoutBooking(),
            'bookings' => array_map(
                /* translators: 1: booking id in the PMS, 2: channel */
                static fn (array $b): string => sprintf(__('Booking %1$s (%2$s)', 'ritmo-revenue'), $b['ref'], $b['channel'] === '' ? __('direct', 'ritmo-revenue') : $b['channel']),
                $detail->bookings
            ),
            'prices' => [
                ['label' => __('Price now (pricing system)', 'ritmo-revenue'), 'price' => PriceText::amount($cell->priceNow, $currency)],
                ['label' => __('Price the PMS shows', 'ritmo-revenue'), 'price' => PriceText::amount($cell->pmsPrice, $currency)],
                ['label' => __('Floor in force', 'ritmo-revenue'), 'price' => PriceText::amount($planned->floorNow, $currency)],
                ['label' => __('Floor proposed by the last run', 'ritmo-revenue'), 'price' => NightText::bound($planned->floorProposed, $planned->floorPercent, $base, $currency)],
                ['label' => __('Ceiling in force', 'ritmo-revenue'), 'price' => PriceText::amount($planned->ceilingNow, $currency)],
                ['label' => __('Ceiling proposed by the last run', 'ritmo-revenue'), 'price' => NightText::bound($planned->ceilingProposed, $planned->ceilingPercent, $base, $currency)],
                ['label' => __('Listing minimum', 'ritmo-revenue'), 'price' => PriceText::amount($detail->room->min, $currency)],
                ['label' => __('Listing base', 'ritmo-revenue'), 'price' => PriceText::amount($base, $currency)],
                ['label' => __('Listing maximum', 'ritmo-revenue'), 'price' => PriceText::amount($detail->room->max, $currency)],
                ['label' => __('No price below (safety limit)', 'ritmo-revenue'), 'price' => PriceText::amount($absolute, $currency)],
            ],
            'price_note' => $cell->priceNow !== null && $cell->pmsPrice !== null && abs($cell->priceNow - $cell->pmsPrice) >= 0.5
                ? __('The PMS shows another price: the new one may not have been delivered yet.', 'ritmo-revenue') : '',
            'stay' => [
                /* translators: %s: nights */
                $cell->minStay === null ? __('Minimum stay: not read', 'ritmo-revenue') : sprintf(_n('Minimum stay: %s night', 'Minimum stay: %s nights', $cell->minStay, 'ritmo-revenue'), number_format_i18n($cell->minStay)),
                self::restriction($cell->restriction),
            ],
            'why' => [
                'status' => NightText::status($planned),
                'pace' => Labels::pace($planned->paceCategory() === 'none' ? null : $planned->paceCategory()),
                'rule' => $planned->status === null ? __('The last run did not price this night.', 'ritmo-revenue') : NightText::reason($planned->reason),
                'sentences' => ReasonText::sentences($cell->trace),
                'orders' => $cell->orderIds,
                'refused' => $planned->issues === [] ? [] : [__('The safety checks refused the change of this night; it is not written.', 'ritmo-revenue')],
            ],
            'freeze' => $detail->freeze === null ? null : [
                'id' => (int) $detail->freeze->id,
                'text' => $detail->freeze->frozenUntil === null ? __('Frozen after a change made by someone else.', 'ritmo-revenue')
                    /* translators: %s: date */
                    : sprintf(__('Frozen until %s after a change made by someone else.', 'ritmo-revenue'), NightDate::instant($detail->freeze->frozenUntil, $property->timezone)),
                'fields' => implode(', ', array_map(Labels::field(...), $detail->freeze->fields)),
            ],
            'can_release' => Capability::can(Capability::APPROVE),
            'can_order' => Capability::can(Capability::MANAGE_RULES),
            'order_url' => Menu::url(Menu::ORDERS, ['tab' => 'active', 'property' => $property->id, 'room' => $detail->room->code, 'night' => $key]),
            'can_undo' => Capability::can(Capability::APPROVE),
            'undo_url' => Menu::url(Menu::CHANGES, ['property' => $property->id, 'room' => $detail->room->code, 'night_from' => $key, 'night_to' => $key]),
            'history' => self::history($detail, $property),
            'property_id' => $property->id,
            'room' => $detail->room->code,
            'night' => $key,
        ];
    }

    /**
     * @return list<array{when: string, text: string}>
     */
    private static function history(NightDetail $detail, Property $property): array
    {
        $rows = [];
        foreach ($detail->changes as $change) {
            $rows[] = ['at' => $change->createdAt, 'text' => self::change($change, $property)];
        }
        foreach ($detail->steps as $step) {
            $rows[] = ['at' => $step['recorded_at'], 'text' => sprintf(
                /* translators: 1: up or down, 2: price before, 3: price after */
                __('Pace step %1$s from %2$s to %3$s', 'ritmo-revenue'),
                $step['direction'] === 'down' ? __('down', 'ritmo-revenue') : __('up', 'ritmo-revenue'),
                PriceText::amount((float) $step['from'], $property->currency)['text'],
                PriceText::amount((float) $step['to'], $property->currency)['text']
            ) . ($step['reached_channel'] ? ' · ' . __('reached the channels', 'ritmo-revenue') : '')];
        }
        usort($rows, static fn (array $a, array $b): int => $b['at'] <=> $a['at']);
        $out = array_map(static fn (array $r): array => ['when' => NightDate::instant($r['at'], $property->timezone), 'text' => $r['text']], array_slice($rows, 0, 50));
        foreach ($detail->decisions as $decision) {
            $out[] = [
                /* translators: %d: run number */
                'when' => sprintf(__('Run %d', 'ritmo-revenue'), $decision['run_id']),
                'text' => NightText::reason($decision['reason']) . ' · ' . match ($decision['status']) {
                    'would_write' => __('would write or wrote', 'ritmo-revenue'),
                    'rejected' => __('refused', 'ritmo-revenue'),
                    default => __('no change', 'ritmo-revenue'),
                },
            ];
        }

        return $out;
    }

    private static function change(ChangeRecord $change, Property $property): string
    {
        $who = ChangeGroups::userId($change->actor);
        $by = $change->undoOf !== null ? __('Undo', 'ritmo-revenue')
            : ($who === null ? __('Autonomous run', 'ritmo-revenue')
            /* translators: %s: name of the person */
            : sprintf(__('Approved by %s', 'ritmo-revenue'), PeopleNames::name($who)));
        $what = Labels::field($change->field);
        $values = $change->oldAmount !== null || $change->newAmount !== null
            ? PriceText::change($change->oldAmount, $change->newAmount, $property->currency)['text']
            : sprintf('%s → %s', self::plain($change->oldValue), self::plain($change->newValue));

        return sprintf('%s: %s (%s)', ucfirst($what), $values, $by);
    }

    private static function plain(int|float|string|null $value): string
    {
        return $value === null ? '—' : (is_string($value) && preg_match('/^[01]{7}$/', $value) === 1 ? __('days of the week', 'ritmo-revenue') : (string) $value);
    }

    private static function restriction(string $restriction): string
    {
        return match ($restriction) {
            'blackout' => __('Closed in the PMS', 'ritmo-revenue'),
            'no_checkin' => __('No arrival this night', 'ritmo-revenue'),
            'no_checkout' => __('No departure this night', 'ritmo-revenue'),
            'no_checkin_checkout' => __('No arrival and no departure this night', 'ritmo-revenue'),
            default => __('Arrival and departure allowed', 'ritmo-revenue'),
        };
    }
}
