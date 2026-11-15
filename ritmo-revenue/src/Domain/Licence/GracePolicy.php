<?php

declare(strict_types=1);

namespace Ritmo\Domain\Licence;

use DateInterval;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * How long a licence keeps working after something went wrong (provider not reached, licence
 * expired or revoked): 30 days unless the site sets another number of days.
 */
final class GracePolicy
{
    public const DEFAULT_DAYS = 30;

    public const MIN_DAYS = 1;

    public const MAX_DAYS = 90;

    /**
     * @throws InvalidArgumentException outside MIN_DAYS..MAX_DAYS
     */
    public function __construct(public readonly int $days = self::DEFAULT_DAYS)
    {
        if ($days < self::MIN_DAYS || $days > self::MAX_DAYS) {
            throw new InvalidArgumentException(sprintf('The licence grace period is %d to %d days.', self::MIN_DAYS, self::MAX_DAYS));
        }
    }

    /**
     * A configured value (a constant of wp-config.php): a whole number of days within the bounds,
     * else the default. Never throws: a typo never shortens or removes the grace period.
     */
    public static function fromSetting(mixed $value): self
    {
        if (is_string($value) && preg_match('/^\d{1,3}$/', trim($value)) === 1) {
            $value = (int) trim($value);
        }
        if (!is_int($value) || $value < self::MIN_DAYS || $value > self::MAX_DAYS) {
            return new self();
        }

        return new self($value);
    }

    /**
     * The end of the grace period that starts at $start (an instant).
     */
    public function endsAt(DateTimeImmutable $start): DateTimeImmutable
    {
        return $start->add(new DateInterval('PT' . ($this->days * 86400) . 'S'));
    }
}
