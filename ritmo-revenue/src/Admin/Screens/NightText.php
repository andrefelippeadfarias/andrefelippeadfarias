<?php

declare(strict_types=1);

namespace Ritmo\Admin\Screens;

use Ritmo\Admin\Components\Labels;
use Ritmo\Admin\Components\Money;
use Ritmo\Admin\Components\NightDate;
use Ritmo\Admin\Components\PriceText;
use Ritmo\Admin\ReasonText;
use Ritmo\Application\Grid\NightCell;
use Ritmo\Application\Grid\PlannedNight;

/**
 * One room night in words for the grid: the visible text of the cell for each "show" mode, its
 * full accessible name, and the reason label (a code without a sentence reads "another rule",
 * never the code).
 */
final class NightText
{
    public const SHOW = ['price', 'floor', 'ceiling', 'stay', 'occupancy', 'pace', 'rule'];

    private function __construct()
    {
    }

    public static function reason(string $code): string
    {
        if ($code === '') {
            return __('no rule', 'ritmo-revenue');
        }
        $label = ReasonText::reason($code);

        return $label === $code ? __('another rule', 'ritmo-revenue') : $label;
    }

    /**
     * The short text of a cell for the "show" mode.
     */
    public static function visible(NightCell $cell, string $show, string $currency): string
    {
        if ($cell->soldOut()) {
            return __('sold out', 'ritmo-revenue');
        }
        if ($cell->closedWithoutBooking()) {
            return __('closed', 'ritmo-revenue');
        }
        $planned = $cell->planned;

        return match ($show) {
            'occupancy' => sprintf('%s/%s', number_format_i18n((int) $cell->unitsSold), number_format_i18n($cell->unitsTotal)),
            'pace' => $planned->status === null ? '—' : Labels::paceSymbol($planned->paceCategory()),
            'rule' => $planned->status === null ? '—' : self::reason($planned->reason),
            /* translators: %s: number of nights */
            'stay' => $cell->minStay === null ? '—' : sprintf(__('%sn', 'ritmo-revenue'), number_format_i18n($cell->minStay)),
            'floor' => self::amount($planned->floorProposed ?? $planned->floorNow, $currency),
            'ceiling' => self::amount($planned->ceilingProposed ?? $planned->ceilingNow, $currency),
            default => self::amount($cell->priceNow, $currency),
        };
    }

    /**
     * The accessible name of the cell link: room, long date, price, occupancy, pace, rule and marks.
     */
    public static function accessible(NightCell $cell, string $roomName, string $currency): string
    {
        $parts = [$roomName . ', ' . NightDate::long($cell->night)];
        if ($cell->period !== '') {
            $parts[] = $cell->period;
        }
        if ($cell->soldOut()) {
            $parts[] = __('sold out', 'ritmo-revenue');
        } elseif ($cell->closedWithoutBooking()) {
            $parts[] = __('closed', 'ritmo-revenue');
        } else {
            /* translators: %s: amount */
            $parts[] = sprintf(__('price %s', 'ritmo-revenue'), $cell->priceNow === null ? __('not read', 'ritmo-revenue') : Money::format($cell->priceNow, $currency));
        }
        /* translators: 1: units sold, 2: units of the room */
        $parts[] = sprintf(__('%1$s of %2$s sold', 'ritmo-revenue'), number_format_i18n((int) $cell->unitsSold), number_format_i18n($cell->unitsTotal));
        $planned = $cell->planned;
        if ($planned->status === null) {
            $parts[] = __('not priced by the plugin', 'ritmo-revenue');
        } else {
            $parts[] = Labels::pace($planned->paceCategory() === 'none' ? null : $planned->paceCategory());
            $parts[] = self::reason($planned->reason);
            $parts[] = self::status($planned);
        }
        if ($cell->frozen) {
            $parts[] = $cell->frozenUntil === null ? __('frozen', 'ritmo-revenue')
                /* translators: %s: date */
                : sprintf(__('frozen until %s', 'ritmo-revenue'), NightDate::day($cell->frozenUntil));
        }
        if ($cell->orderIds !== []) {
            $parts[] = __('owner order', 'ritmo-revenue');
        }
        if ($cell->alertCount > 0) {
            /* translators: %s: number of alerts */
            $parts[] = sprintf(_n('%s open alert', '%s open alerts', $cell->alertCount, 'ritmo-revenue'), number_format_i18n($cell->alertCount));
        }

        return implode('; ', array_filter($parts, static fn (string $p): bool => $p !== ''));
    }

    public static function status(PlannedNight $planned): string
    {
        return match ($planned->status) {
            PlannedNight::WOULD_WRITE => __('the last run would change it', 'ritmo-revenue'),
            PlannedNight::REJECTED => __('refused by the safety checks', 'ritmo-revenue'),
            PlannedNight::NO_CHANGE => __('no change', 'ritmo-revenue'),
            default => '',
        };
    }

    /**
     * A floor or ceiling of the detail, money first with the percent of the base in words.
     *
     * @return array{text: string, note: string, sr: string}
     */
    public static function bound(?float $amount, ?int $percent, float $base, string $currency): array
    {
        if ($amount === null) {
            return PriceText::amount(null, $currency);
        }

        return $percent === null || $base <= 0 ? PriceText::amount($amount, $currency) : PriceText::withBase($amount, $percent, $base, $currency);
    }

    private static function amount(?float $amount, string $currency): string
    {
        return $amount === null ? '—' : Money::format($amount, $currency);
    }
}
