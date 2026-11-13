<?php

declare(strict_types=1);

namespace Ritmo\Domain\Alerts;

/**
 * Bookings worth less than expected: total / (sum of the PMS prices of its nights x the share of
 * that price the booking's channel carries). Off until the channel factors are set.
 */
final class BelowExpectedConfig
{
    public const ANY_PROPERTY = '*';

    /**
     * @param array<string, array<string, float>> $channelFactors property id (or "*") => channel => factor
     */
    public function __construct(
        public readonly bool $enabled = false,
        public readonly float $threshold = 0.70,
        public readonly float $criticalThreshold = 0.50,
        public readonly float $defaultFactor = 1.0,
        public readonly PriceBasis $priceBasis = PriceBasis::AtBooking,
        public readonly array $channelFactors = [],
    ) {
    }

    /**
     * The factor of a channel for a property: the property's own, the site's ("*"), or the default.
     */
    public function factor(string $propertyId, string $channel): float
    {
        return $this->channelFactors[$propertyId][$channel]
            ?? $this->channelFactors[self::ANY_PROPERTY][$channel]
            ?? $this->defaultFactor;
    }
}
