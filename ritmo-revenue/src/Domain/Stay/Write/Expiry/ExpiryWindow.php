<?php

declare(strict_types=1);

namespace Ritmo\Domain\Stay\Write\Expiry;

use DateTimeImmutable;
use InvalidArgumentException;
use Ritmo\Domain\Support\Days;

/**
 * The lead-time expiry of a date rule of the pricing system (spec 06, D2): a rule of night N
 * with an expiry of E days applies only while the day is before N - E (the reference's lesson: a
 * rule of the 11th with 3 days stopped applying on the 8th). The plugin reads the expiry and never
 * sends it (W37). Boundary to confirm with the provider's documentation before release (D-D2).
 */
final class ExpiryWindow
{
    public readonly DateTimeImmutable $night;

    /**
     * @throws InvalidArgumentException on a negative number of days
     */
    public function __construct(DateTimeImmutable $night, public readonly int $days)
    {
        if ($days < 0) {
            throw new InvalidArgumentException('A lead-time expiry cannot be negative.');
        }
        $this->night = Days::normalize($night);
    }

    /**
     * The expiry as the connector reads it: a whole number of days, else none (the caller warns).
     */
    public static function read(DateTimeImmutable $night, mixed $value): ?self
    {
        if (is_int($value) && $value >= 0) {
            return new self($night, $value);
        }
        if (is_string($value) && preg_match('/^\d{1,4}$/', $value) === 1) {
            return new self($night, (int) $value);
        }

        return null;
    }

    public function activeOn(DateTimeImmutable $day): bool
    {
        return Days::normalize($day) < Days::add($this->night, -$this->days);
    }

    /**
     * The last day the rule applies (night - days - 1): the value NightFields::EXPIRY takes.
     */
    public function lastActiveDay(): DateTimeImmutable
    {
        return Days::add($this->night, -$this->days - 1);
    }
}
