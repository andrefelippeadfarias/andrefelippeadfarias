<?php

declare(strict_types=1);

namespace Ritmo\Domain\Write\State;

/**
 * The names of the values the write path compares, records and undoes, one per field of an
 * external system. A channel limit of the PMS is "pms_channel_limit:<channel>".
 */
final class ChangeField
{
    public const FLOOR_PCT = 'floor_pct';
    public const CEILING_PCT = 'ceiling_pct';
    public const MIN_STAY = 'min_stay';
    public const CHECK_IN_MASK = 'check_in_mask';
    public const CHECK_OUT_MASK = 'check_out_mask';
    /** A price written by someone else (the plugin never writes one). */
    public const PRICE = 'price';
    public const PRICE_TYPE = 'price_type';
    /** A floor or ceiling as an amount, written by someone else. */
    public const FLOOR_FIXED = 'floor_fixed';
    public const CEILING_FIXED = 'ceiling_fixed';
    /** The last day the date rule is in force (lead-time expiry), when the connector reads it. */
    public const EXPIRY = 'expiry';
    /** Presence of the date rule: only in the change log, never in a NightFields map. */
    public const RULE = 'rule';

    public const PMS_MIN_STAY = 'pms_min_stay';
    public const PMS_MAX_STAY = 'pms_max_stay';
    public const PMS_RESTRICTION = 'pms_restriction';
    public const PMS_AVAILABLE = 'pms_available';
    public const PMS_CHANNEL_LIMIT = 'pms_channel_limit:';

    public const LISTING_MIN = 'listing_min';
    public const LISTING_BASE = 'listing_base';
    public const LISTING_MAX = 'listing_max';

    /** The channels whose limit the PMS takes. */
    public const CHANNELS = ['booking', 'expedia', 'airbnb'];

    /** The fields of a date rule the plugin writes (and compares after writing). */
    public const WRITABLE_PRICING = [self::FLOOR_PCT, self::CEILING_PCT, self::MIN_STAY, self::CHECK_IN_MASK, self::CHECK_OUT_MASK];

    private const PRICING = [...self::WRITABLE_PRICING, self::PRICE, self::PRICE_TYPE, self::FLOOR_FIXED, self::CEILING_FIXED, self::EXPIRY];

    private const PMS = [self::PMS_MIN_STAY, self::PMS_MAX_STAY, self::PMS_RESTRICTION, self::PMS_AVAILABLE];

    private const LISTING = [self::LISTING_MIN, self::LISTING_BASE, self::LISTING_MAX];

    private const PERCENT = [self::FLOOR_PCT, self::CEILING_PCT];

    private function __construct()
    {
    }

    public static function isKnown(string $field): bool
    {
        return self::providerOf($field) !== null;
    }

    public static function providerOf(string $field): ?Provider
    {
        return match (true) {
            in_array($field, self::PRICING, true) => Provider::Pricing,
            in_array($field, self::PMS, true), self::channelOf($field) !== null => Provider::Pms,
            in_array($field, self::LISTING, true) => Provider::Listing,
            default => null,
        };
    }

    /**
     * The channel of a channel-limit field, or null for any other field.
     */
    public static function channelOf(string $field): ?string
    {
        if (!str_starts_with($field, self::PMS_CHANNEL_LIMIT)) {
            return null;
        }
        $channel = substr($field, strlen(self::PMS_CHANNEL_LIMIT));

        return in_array($channel, self::CHANNELS, true) ? $channel : null;
    }

    public static function channelLimit(string $channel): string
    {
        return self::PMS_CHANNEL_LIMIT . $channel;
    }

    /**
     * True for a floor or ceiling written as a whole percentage of the listing base.
     */
    public static function isPercent(string $field): bool
    {
        return in_array($field, self::PERCENT, true);
    }
}
