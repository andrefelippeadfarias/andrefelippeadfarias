<?php

declare(strict_types=1);

namespace Ritmo\Domain\Support;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use InvalidArgumentException;

/**
 * Date-only helpers.
 *
 * Convention used by the whole domain: a calendar date (a night, an arrival, "today") is a
 * DateTimeImmutable at 00:00:00 UTC. Dates never carry a local timezone, so day arithmetic is
 * immune to daylight saving. Instants (created at, pushed at) are DateTimeImmutable in UTC with
 * their real time of day.
 */
final class Days
{
    private const FORMAT = 'Y-m-d';

    /**
     * Parses a strict "YYYY-MM-DD" string into a date-only value.
     *
     * @throws InvalidArgumentException when the string is not a real calendar date in that format
     */
    public static function date(string $ymd): DateTimeImmutable
    {
        $date = DateTimeImmutable::createFromFormat('!' . self::FORMAT, $ymd, new DateTimeZone('UTC'));
        if ($date === false || $date->format(self::FORMAT) !== $ymd) {
            throw new InvalidArgumentException(sprintf('Invalid date "%s": expected YYYY-MM-DD.', $ymd));
        }

        return $date;
    }

    /**
     * True when the string is a real calendar date in "YYYY-MM-DD".
     */
    public static function isValid(string $ymd): bool
    {
        $date = DateTimeImmutable::createFromFormat('!' . self::FORMAT, $ymd, new DateTimeZone('UTC'));

        return $date !== false && $date->format(self::FORMAT) === $ymd;
    }

    /**
     * The "YYYY-MM-DD" key of a date, read in the value's own timezone.
     */
    public static function key(DateTimeInterface $date): string
    {
        return $date->format(self::FORMAT);
    }

    /**
     * Normalizes any date-time to the date-only value of its own calendar date.
     */
    public static function normalize(DateTimeInterface $date): DateTimeImmutable
    {
        return self::date($date->format(self::FORMAT));
    }

    /**
     * The calendar date of an instant in an IANA timezone, as a date-only value.
     *
     * @throws InvalidArgumentException when the timezone is not valid
     */
    public static function localDate(DateTimeInterface $instant, string $timezone): DateTimeImmutable
    {
        try {
            $zone = new DateTimeZone($timezone);
        } catch (\Exception $e) {
            throw new InvalidArgumentException(sprintf('Invalid timezone "%s".', $timezone), 0, $e);
        }

        return self::normalize(DateTimeImmutable::createFromInterface($instant)->setTimezone($zone));
    }

    /**
     * The date moved by a number of days (negative moves back).
     */
    public static function add(DateTimeInterface $date, int $days): DateTimeImmutable
    {
        return self::normalize($date)->modify(sprintf('%+d days', $days));
    }

    /**
     * Signed number of days from $from to $to (positive when $to is later). This is the "D" of
     * the pace rules when $from is today and $to is the night.
     */
    public static function diff(DateTimeInterface $from, DateTimeInterface $to): int
    {
        $seconds = self::normalize($to)->getTimestamp() - self::normalize($from)->getTimestamp();

        return intdiv($seconds, 86400);
    }

    /**
     * Day of the week as Python's date.weekday(): Monday = 0 ... Sunday = 6.
     */
    public static function weekday(DateTimeInterface $date): int
    {
        return (int) $date->format('N') - 1;
    }
}
