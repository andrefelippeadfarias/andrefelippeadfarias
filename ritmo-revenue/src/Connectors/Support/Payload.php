<?php

declare(strict_types=1);

namespace Ritmo\Connectors\Support;

use DateTimeImmutable;
use DateTimeZone;
use Ritmo\Domain\Support\Days;

/**
 * Typed, forgiving readers for the fields of a decoded API payload.
 *
 * External APIs send numbers as text, omit fields and use sentinels. Every reader answers null
 * (or the neutral value) for anything that is not what was asked for, so a mapper states what
 * it needs and never trusts the shape of what arrived.
 */
final class Payload
{
    private const INSTANT_PATTERN = '/^\d{4}-\d{2}-\d{2}([T ]\d{2}:\d{2}(:\d{2}(\.\d{1,9})?)?)?(Z|[+-]\d{2}:?\d{2})?$/';

    /**
     * A scalar as trimmed text without control characters, cut to a maximum length.
     *
     * @param array<int|string, mixed> $row
     */
    public static function text(array $row, string $key, int $maxLength = 255): string
    {
        $value = $row[$key] ?? null;
        if (!is_string($value) && !is_int($value) && !is_float($value)) {
            return '';
        }

        $clean = trim((string) preg_replace('/[\x00-\x1f\x7f]+/', ' ', (string) $value));

        return function_exists('mb_substr') ? mb_substr($clean, 0, $maxLength) : substr($clean, 0, $maxLength);
    }

    /**
     * A finite number, given as a number or as numeric text.
     *
     * @param array<int|string, mixed> $row
     */
    public static function number(array $row, string $key): ?float
    {
        $value = $row[$key] ?? null;
        if (is_string($value)) {
            $value = trim($value);
        }
        if (is_bool($value) || !is_numeric($value)) {
            return null;
        }

        $number = (float) $value;

        return is_finite($number) ? $number : null;
    }

    /**
     * A whole number; a number with a fraction is not one.
     *
     * @param array<int|string, mixed> $row
     */
    public static function integer(array $row, string $key): ?int
    {
        $number = self::number($row, $key);
        if ($number === null || $number !== (float) (int) $number) {
            return null;
        }

        return (int) $number;
    }

    /**
     * A number greater than zero (APIs use 0 and -1 for "no value").
     *
     * @param array<int|string, mixed> $row
     */
    public static function positive(array $row, string $key): ?float
    {
        $number = self::number($row, $key);

        return $number !== null && $number > 0 ? $number : null;
    }

    /**
     * A whole number of at least one.
     *
     * @param array<int|string, mixed> $row
     */
    public static function count(array $row, string $key): ?int
    {
        $number = self::integer($row, $key);

        return $number !== null && $number >= 1 ? $number : null;
    }

    /**
     * True for true, 1, "1", "true" and "yes".
     *
     * @param array<int|string, mixed> $row
     */
    public static function flag(array $row, string $key): bool
    {
        $value = $row[$key] ?? null;
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value)) {
            return (float) $value === 1.0;
        }

        return is_string($value) && in_array(strtolower(trim($value)), ['1', 'true', 'yes'], true);
    }

    /**
     * A calendar date from text that starts with YYYY-MM-DD.
     *
     * @param array<int|string, mixed> $row
     */
    public static function date(array $row, string $key): ?DateTimeImmutable
    {
        $value = $row[$key] ?? null;
        if (!is_string($value)) {
            return null;
        }

        $day = substr(trim($value), 0, 10);

        return Days::isValid($day) ? Days::date($day) : null;
    }

    /**
     * An instant in UTC from ISO 8601 text. Text without an offset is read as UTC.
     *
     * @param array<int|string, mixed> $row
     */
    public static function instant(array $row, string $key): ?DateTimeImmutable
    {
        $value = $row[$key] ?? null;
        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);
        if (preg_match(self::INSTANT_PATTERN, $value) !== 1 || !Days::isValid(substr($value, 0, 10))) {
            return null;
        }

        $utc = new DateTimeZone('UTC');
        try {
            return (new DateTimeImmutable($value, $utc))->setTimezone($utc);
        } catch (\Exception) {
            return null;
        }
    }

    /**
     * The entries of a list that are themselves arrays (objects of the payload).
     *
     * @param array<int|string, mixed> $row
     *
     * @return list<array<int|string, mixed>>
     */
    public static function rows(array $row, string $key): array
    {
        $value = $row[$key] ?? null;

        return is_array($value) ? array_values(array_filter($value, 'is_array')) : [];
    }
}
