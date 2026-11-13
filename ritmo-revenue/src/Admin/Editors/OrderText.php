<?php

declare(strict_types=1);

namespace Ritmo\Admin\Editors;

use Ritmo\Admin\Components\Money;
use Ritmo\Domain\Orders\OrderRelease;
use Ritmo\Domain\Orders\OrderType;
use Ritmo\Domain\Orders\OwnerOrder;

/**
 * An owner order in words (spec 05, 5.8): what it does to its nights and what it releases, each
 * a plain sentence. Amounts in money; rooms by name; never a code.
 */
final class OrderText
{
    private function __construct()
    {
    }

    /**
     * @param array<string, string> $roomNames code => name
     */
    public static function describe(OwnerOrder $order, array $roomNames, string $currency): string
    {
        return match ($order->type) {
            OrderType::FixedPrice => __('The plugin does not touch these nights: you set the price yourself.', 'ritmo-revenue'),
            OrderType::Floor => sprintf(
                /* translators: %s: minimum prices per room */
                __('Minimum price %s (price sent to the channels).', 'ritmo-revenue'),
                self::amounts($order->floors, $roomNames, $currency)
            ),
            OrderType::Target => self::target($order, $roomNames, $currency),
            OrderType::Rule => __('A standing permission: the plugin may do what it releases on these nights.', 'ritmo-revenue'),
            OrderType::Restriction => __('An exception to the stay and arrival rules on these nights.', 'ritmo-revenue'),
            OrderType::FollowUp => __('A reminder only: it allows nothing.', 'ritmo-revenue'),
            OrderType::Manual => __('Someone changed these nights by hand: the plugin leaves them alone for a few days.', 'ritmo-revenue'),
        };
    }

    public static function type(OrderType $type): string
    {
        return match ($type) {
            OrderType::FixedPrice => __('Leave these nights alone', 'ritmo-revenue'),
            OrderType::Floor => __('Minimum price', 'ritmo-revenue'),
            OrderType::Target => __('Market target', 'ritmo-revenue'),
            OrderType::Rule => __('Standing permission', 'ritmo-revenue'),
            OrderType::Restriction => __('Stay or arrival exception', 'ritmo-revenue'),
            OrderType::FollowUp => __('Reminder only', 'ritmo-revenue'),
            OrderType::Manual => __('Changed by hand', 'ritmo-revenue'),
        };
    }

    public static function release(OrderRelease $release): string
    {
        return match ($release) {
            OrderRelease::FixedPrice => __('A fixed price for the night.', 'ritmo-revenue'),
            OrderRelease::BelowStandardFloor => __('A price below the standard floors (never below the absolute floor of the room).', 'ritmo-revenue'),
            OrderRelease::AboveWeeklyRiseLimit => __('A rise beyond the weekly limit of the listing.', 'ritmo-revenue'),
            OrderRelease::Stay => __('A minimum stay other than the usual one.', 'ritmo-revenue'),
            OrderRelease::OrphanStay => __('A one-night stay on a night left alone between bookings.', 'ritmo-revenue'),
            OrderRelease::ArrivalDeparture => __('Arrival or departure on days that are usually closed.', 'ritmo-revenue'),
            OrderRelease::DiscountBand => __('A price under the lower edge of a share of another room.', 'ritmo-revenue'),
            OrderRelease::Listing => __('A change of the listing itself (its minimum, base or maximum), still only with approval.', 'ritmo-revenue'),
            OrderRelease::Inventory => __('A change of the rooms for sale in the PMS.', 'ritmo-revenue'),
            OrderRelease::Customization => __('A change of a setting of the pricing system.', 'ritmo-revenue'),
        };
    }

    /**
     * @param array<string, string> $roomNames
     */
    public static function rooms(OwnerOrder $order, array $roomNames): string
    {
        if (in_array(OwnerOrder::ALL_ROOMS, $order->rooms, true)) {
            return __('All rooms', 'ritmo-revenue');
        }

        return implode(', ', array_map(static fn (string $r): string => $roomNames[$r] ?? $r, $order->rooms));
    }

    /**
     * @param array<string, string> $roomNames
     */
    private static function target(OwnerOrder $order, array $roomNames, string $currency): string
    {
        $percentile = rtrim(rtrim(sprintf('%.1F', (float) $order->percentile), '0'), '.');
        if ($order->floors === []) {
            /* translators: %s: market percentile */
            return sprintf(__('Price at the market percentile %s.', 'ritmo-revenue'), $percentile);
        }

        /* translators: 1: market percentile, 2: minimum prices per room */
        return sprintf(__('Price at the market percentile %1$s, never below %2$s on weak nights.', 'ritmo-revenue'), $percentile, self::amounts($order->floors, $roomNames, $currency));
    }

    /**
     * @param array<int|string, float> $amounts room => amount
     * @param array<string, string>    $roomNames
     */
    private static function amounts(array $amounts, array $roomNames, string $currency): string
    {
        $parts = [];
        foreach ($amounts as $room => $amount) {
            /* translators: 1: amount, 2: room */
            $parts[] = sprintf(__('%1$s for %2$s', 'ritmo-revenue'), Money::format($amount, $currency), $roomNames[(string) $room] ?? (string) $room);
        }

        return implode('; ', $parts);
    }
}
