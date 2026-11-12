<?php

declare(strict_types=1);

namespace Ritmo\Domain\Support;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use InvalidArgumentException;

/**
 * Clock frozen at one instant. Used by tests, golden fixtures and replays of past runs.
 */
final class FixedClock implements Clock
{
    private const ISO_PATTERN = '/^\d{4}-\d{2}-\d{2}([T ]\d{2}:\d{2}(:\d{2}(\.\d{1,6})?)?)?(Z|[+-]\d{2}:?\d{2})?$/';

    private DateTimeImmutable $now;

    /**
     * @param DateTimeInterface|string $now an instant; a string without offset is read as UTC
     *
     * @throws InvalidArgumentException when the string is not a valid date-time
     */
    public function __construct(DateTimeInterface|string $now)
    {
        $utc = new DateTimeZone('UTC');
        if (is_string($now)) {
            // Only absolute ISO 8601 values: a relative string such as "now" would read the system clock.
            if (preg_match(self::ISO_PATTERN, $now) !== 1) {
                throw new InvalidArgumentException(sprintf('Invalid instant "%s": expected ISO 8601.', $now));
            }
            try {
                $now = new DateTimeImmutable($now, $utc);
            } catch (\Exception $e) {
                throw new InvalidArgumentException(sprintf('Invalid instant "%s".', $now), 0, $e);
            }
        }

        $this->now = DateTimeImmutable::createFromInterface($now)->setTimezone($utc);
    }

    public function now(): DateTimeImmutable
    {
        return $this->now;
    }

    public function today(string $timezone): DateTimeImmutable
    {
        return Days::localDate($this->now, $timezone);
    }

    /**
     * A new clock moved by the given number of seconds (negative moves back).
     */
    public function plusSeconds(int $seconds): self
    {
        return new self($this->now->modify(sprintf('%+d seconds', $seconds)));
    }
}
