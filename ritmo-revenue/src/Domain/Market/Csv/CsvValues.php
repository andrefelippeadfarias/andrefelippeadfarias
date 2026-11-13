<?php

declare(strict_types=1);

namespace Ritmo\Domain\Market\Csv;

use DateTimeImmutable;
use DateTimeZone;
use Exception;
use Ritmo\Domain\Support\Days;

/**
 * Strict readers of the cells of a market price file. Each returns null when the text does not
 * have the documented format: nothing is guessed.
 */
final class CsvValues
{
    private function __construct()
    {
    }

    /**
     * "480", "480.50" or "480,50": positive, at most 7 whole digits and 2 decimals, no thousands
     * separator (a comma or dot is decimal only before 1 or 2 final digits).
     */
    public static function price(string $text): ?float
    {
        if (preg_match('/^(\d{1,7})(?:[.,](\d{1,2}))?$/', $text, $m) !== 1) {
            return null;
        }
        $value = (float) ($m[1] . (isset($m[2]) ? '.' . $m[2] : ''));

        return $value > 0 ? $value : null;
    }

    /**
     * A calendar date "YYYY-MM-DD", or null.
     */
    public static function date(string $text): ?DateTimeImmutable
    {
        return Days::isValid($text) ? Days::date($text) : null;
    }

    /**
     * "1", "0", "yes", "no", "true", "false" (any case).
     */
    public static function flag(string $text): ?bool
    {
        return match (strtolower($text)) {
            '1', 'yes', 'true' => true,
            '0', 'no', 'false' => false,
            default => null,
        };
    }

    /**
     * When a price was captured, as a UTC instant: "YYYY-MM-DD" (00:00 at the property),
     * "YYYY-MM-DD HH:MM[:SS]" (at the property) or ISO 8601 with "Z" or an offset.
     */
    public static function capture(string $text, string $timezone): ?DateTimeImmutable
    {
        $local = new DateTimeZone($timezone);
        if (Days::isValid($text)) {
            return self::toUtc(DateTimeImmutable::createFromFormat('!Y-m-d', $text, $local));
        }
        if (preg_match('/^(\d{4}-\d{2}-\d{2})[ T]([01]\d|2[0-3]):([0-5]\d)(?::([0-5]\d))?(Z|[+-](?:[01]\d|2[0-3]):?[0-5]\d)?$/', $text, $m) !== 1 || !Days::isValid($m[1])) {
            return null;
        }
        $zone = ($m[5] ?? '') === '' ? $local : self::zone($m[5]);
        try {
            $instant = new DateTimeImmutable(sprintf('%s %s:%s:%s', $m[1], $m[2], $m[3], ($m[4] ?? '') === '' ? '00' : $m[4]), $zone);
        } catch (Exception) {
            return null;
        }

        return self::toUtc($instant);
    }

    private static function zone(string $offset): DateTimeZone
    {
        return new DateTimeZone($offset === 'Z' ? 'UTC' : (strlen($offset) === 5 ? substr($offset, 0, 3) . ':' . substr($offset, 3) : $offset));
    }

    private static function toUtc(DateTimeImmutable|false $instant): ?DateTimeImmutable
    {
        return $instant === false ? null : $instant->setTimezone(new DateTimeZone('UTC'));
    }
}
