<?php

declare(strict_types=1);

namespace Ritmo\Admin\Components;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

/**
 * Nights and instants as a person reads them. A night is a date-only value at UTC midnight, so
 * it is printed in UTC and never shifts a day; an instant is printed in the property's timezone
 * with the site's formats. Weekday and month names come from WordPress (translated).
 */
final class NightDate
{
    private function __construct()
    {
    }

    /** "Fri 20 Nov" */
    public static function short(DateTimeInterface $night): string
    {
        /* translators: date format of a night in a table: https://www.php.net/manual/datetime.format.php */
        return self::night(__('D j M', 'ritmo-revenue'), $night);
    }

    /** "Friday, 20 November 2026" */
    public static function long(DateTimeInterface $night): string
    {
        /* translators: date format of a night in a heading: https://www.php.net/manual/datetime.format.php */
        return self::night(__('l, j F Y', 'ritmo-revenue'), $night);
    }

    /** "Fri" */
    public static function weekday(DateTimeInterface $night): string
    {
        return self::night('D', $night);
    }

    /** "20 Nov" */
    public static function day(DateTimeInterface $night): string
    {
        /* translators: day and month of a night: https://www.php.net/manual/datetime.format.php */
        return self::night(__('j M', 'ritmo-revenue'), $night);
    }

    /** "20–22 Nov", "30 Dec – 2 Jan", or one night when both are the same. */
    public static function range(DateTimeInterface $first, DateTimeInterface $last): string
    {
        if ($first->format('Y-m-d') === $last->format('Y-m-d')) {
            return self::day($first);
        }
        if ($first->format('Y-m') === $last->format('Y-m')) {
            return $first->format('j') . '–' . self::day($last);
        }

        return self::day($first) . ' – ' . self::day($last);
    }

    /** Date and time of an instant in a timezone, with the site's formats. */
    public static function instant(DateTimeInterface $instant, string $timezone): string
    {
        return (string) wp_date(get_option('date_format') . ' ' . get_option('time_format'), $instant->getTimestamp(), self::zone($timezone));
    }

    /** Only the time of an instant ("07:31"). */
    public static function time(DateTimeInterface $instant, string $timezone): string
    {
        return (string) wp_date((string) get_option('time_format'), $instant->getTimestamp(), self::zone($timezone));
    }

    /** "12 minutes ago" or "in 3 hours". */
    public static function relative(DateTimeInterface $instant, DateTimeImmutable $now): string
    {
        $diff = human_time_diff($instant->getTimestamp(), $now->getTimestamp());

        return $instant <= $now
            /* translators: %s: a duration such as "12 minutes" */
            ? sprintf(__('%s ago', 'ritmo-revenue'), $diff)
            /* translators: %s: a duration such as "3 hours" */
            : sprintf(__('in %s', 'ritmo-revenue'), $diff);
    }

    private static function night(string $format, DateTimeInterface $night): string
    {
        return (string) wp_date($format, $night->getTimestamp(), new DateTimeZone('UTC'));
    }

    private static function zone(string $timezone): DateTimeZone
    {
        return in_array($timezone, DateTimeZone::listIdentifiers(), true) ? new DateTimeZone($timezone) : new DateTimeZone('UTC');
    }
}
