<?php

declare(strict_types=1);

namespace Ritmo\Domain\Model;

use DateTimeImmutable;
use InvalidArgumentException;
use Ritmo\Domain\Support\Days;

/**
 * A date-specific override stored in the pricing system for one listing night.
 *
 * Floors and ceilings written by the plugin are always percentages over the listing base
 * ($minPercent / $maxPercent: -31 means base x 0.69). Overrides made by hand in the pricing
 * system may hold absolute amounts or a price instead; those are kept in the optional fields so
 * that reading never loses what is in force.
 */
final class DateOverride
{
    public const PRICE_FIXED = 'fixed';

    public const PRICE_PERCENT = 'percent';

    public readonly DateTimeImmutable $date;

    /**
     * @param float|null  $minPercent   floor as a percentage over the base
     * @param float|null  $maxPercent   ceiling as a percentage over the base
     * @param string|null $checkinMask  seven characters 0/1, Monday to Sunday: weekdays open for arrival
     * @param string|null $checkoutMask seven characters 0/1, Monday to Sunday: weekdays open for departure
     * @param string      $reason       free text stored with the override
     * @param float|null  $price        price override: an amount when $priceType is "fixed", a percentage
     *                                  over the recommended price when it is "percent"
     * @param string|null $priceType    "fixed", "percent" or null when there is no price override
     * @param float|null  $minFixed     floor stored as an absolute amount
     * @param float|null  $maxFixed     ceiling stored as an absolute amount
     *
     * @throws InvalidArgumentException on invalid masks, stay or price type
     */
    public function __construct(
        DateTimeImmutable $date,
        public readonly ?float $minPercent = null,
        public readonly ?float $maxPercent = null,
        public readonly ?int $minStay = null,
        public readonly ?string $checkinMask = null,
        public readonly ?string $checkoutMask = null,
        public readonly string $reason = '',
        public readonly ?float $price = null,
        public readonly ?string $priceType = null,
        public readonly ?float $minFixed = null,
        public readonly ?float $maxFixed = null,
    ) {
        foreach ([$checkinMask, $checkoutMask] as $mask) {
            if ($mask !== null && !self::isValidMask($mask)) {
                throw new InvalidArgumentException('A weekday mask has seven characters, each 0 or 1, Monday to Sunday.');
            }
        }
        if ($minStay !== null && $minStay < 1) {
            throw new InvalidArgumentException('The minimum stay must be at least one night.');
        }
        if (($price === null) !== ($priceType === null)) {
            throw new InvalidArgumentException('A price override needs both the price and its type.');
        }
        if ($priceType !== null && $priceType !== self::PRICE_FIXED && $priceType !== self::PRICE_PERCENT) {
            throw new InvalidArgumentException(sprintf('Invalid price type "%s".', $priceType));
        }
        $this->date = Days::normalize($date);
    }

    /**
     * True for a seven-character 0/1 string (Monday to Sunday).
     */
    public static function isValidMask(string $mask): bool
    {
        return preg_match('/^[01]{7}$/', $mask) === 1;
    }

    /**
     * True when the override sets a price, a floor or a ceiling (not only stay or arrival rules).
     */
    public function hasPriceRule(): bool
    {
        return $this->price !== null
            || $this->minPercent !== null
            || $this->maxPercent !== null
            || $this->minFixed !== null
            || $this->maxFixed !== null;
    }
}
