<?php

declare(strict_types=1);

namespace Ritmo\Connectors\PriceLabs;

use Ritmo\Connectors\Support\Payload;
use Ritmo\Domain\Model\DateOverride;
use Ritmo\Domain\Stay\Write\Expiry\ExpiryWindow;

/**
 * Turns a date-specific override of PriceLabs into a DateOverride.
 *
 * Amounts arrive as text. A floor or ceiling is a variation in % over the listing base
 * ("percent_base", also the meaning when the type is missing) or an amount ("fixed"); a price
 * is an amount ("fixed") or a variation in % ("percent"). A value of a type this class does not
 * know is left out, as the reference engine does: it cannot be read as either.
 */
final class OverrideMapper
{
    private const PERCENT_OF_BASE = 'percent_base';

    private const FIXED = 'fixed';

    private const PRICE_TYPES = ['fixed' => DateOverride::PRICE_FIXED, 'percent' => DateOverride::PRICE_PERCENT];

    private const REASON_MAX_LENGTH = 255;

    /**
     * @param array<int|string, mixed> $override
     *
     * @return DateOverride|null null for an item without a date
     */
    public function map(array $override): ?DateOverride
    {
        $date = Payload::date($override, 'date');
        if ($date === null) {
            return null;
        }

        $price = Payload::number($override, 'price');
        $priceType = self::PRICE_TYPES[strtolower(Payload::text($override, 'price_type', 32))] ?? null;
        if ($price === null || $priceType === null) {
            $price = null;
            $priceType = null;
        }

        $masksInForce = !in_array(strtolower(Payload::text($override, 'check_in_check_out_enabled', 8)), ['0', 'false'], true);

        return new DateOverride(
            $date,
            self::bound($override, 'min_price', self::PERCENT_OF_BASE),
            self::bound($override, 'max_price', self::PERCENT_OF_BASE),
            Payload::count($override, 'min_stay'),
            $masksInForce ? self::mask($override, 'check_in') : null,
            $masksInForce ? self::mask($override, 'check_out') : null,
            Payload::text($override, 'reason', self::REASON_MAX_LENGTH),
            $price,
            $priceType,
            self::bound($override, 'min_price', self::FIXED),
            self::bound($override, 'max_price', self::FIXED),
            ExpiryWindow::read($date, $override['lead_time_expiry'] ?? null)?->days
        );
    }

    /**
     * The floor or ceiling of the override when it is of the wanted type.
     *
     * @param array<int|string, mixed> $override
     */
    private static function bound(array $override, string $field, string $wantedType): ?float
    {
        $type = strtolower(Payload::text($override, $field . '_type', 32));
        if ($type === '') {
            $type = self::PERCENT_OF_BASE;
        }

        return $type === $wantedType ? Payload::number($override, $field) : null;
    }

    /**
     * @param array<int|string, mixed> $override
     */
    private static function mask(array $override, string $field): ?string
    {
        $mask = Payload::text($override, $field, 16);

        return DateOverride::isValidMask($mask) ? $mask : null;
    }
}
