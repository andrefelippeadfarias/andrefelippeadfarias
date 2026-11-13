<?php

declare(strict_types=1);

namespace Ritmo\Domain\Stay\Write;

/**
 * The booking limits per channel of one PMS night as the inventory read gives them (spec 06, D3):
 * channels.<name>.maxBookings as a number or numeric text ("0"); a channel without a limit is
 * absent; any other value is left out and reported, so a read never invents a limit.
 */
final class ChannelLimits
{
    /**
     * @param array<string, int> $limits   channel => limit
     * @param list<string>       $ignored  channels whose value was not a whole number of bookings
     */
    private function __construct(public readonly array $limits, public readonly array $ignored)
    {
    }

    public static function read(mixed $channels): self
    {
        if (!is_array($channels)) {
            return new self([], []);
        }
        $limits = $ignored = [];
        foreach ($channels as $name => $channel) {
            if (!is_string($name) || preg_match('/^[a-z0-9_]{1,32}$/', $name) !== 1) {
                continue;
            }
            $value = is_array($channel) ? ($channel['maxBookings'] ?? null) : null;
            if ($value === null || $value === '') {
                continue;
            }
            if (is_int($value) && $value >= 0) {
                $limits[$name] = $value;
            } elseif (is_string($value) && preg_match('/^\d{1,4}$/', $value) === 1) {
                $limits[$name] = (int) $value;
            } else {
                $ignored[] = $name;
            }
        }
        ksort($limits);

        return new self($limits, $ignored);
    }
}
