<?php

declare(strict_types=1);

namespace Ritmo\Infrastructure\Db;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use InvalidArgumentException;
use JsonException;
use Ritmo\Domain\Support\Days;

/**
 * How values are written to and read from the tables, in one place:
 * instants as "YYYY-MM-DD HH:MM:SS" in UTC, nights as "YYYY-MM-DD", structures as JSON text.
 */
final class DbValue
{
    private const INSTANT_FORMAT = 'Y-m-d H:i:s';

    public static function instant(DateTimeInterface $instant): string
    {
        return DateTimeImmutable::createFromInterface($instant)->setTimezone(new DateTimeZone('UTC'))->format(self::INSTANT_FORMAT);
    }

    public static function nullableInstant(?DateTimeInterface $instant): ?string
    {
        return $instant === null ? null : self::instant($instant);
    }

    /**
     * @throws DatabaseException when a stored value is not an instant
     */
    public static function toInstant(?string $stored): ?DateTimeImmutable
    {
        if ($stored === null || $stored === '') {
            return null;
        }

        $instant = DateTimeImmutable::createFromFormat('!' . self::INSTANT_FORMAT, substr($stored, 0, 19), new DateTimeZone('UTC'));
        if ($instant === false) {
            throw new DatabaseException(sprintf('Stored instant "%s" is unreadable.', $stored));
        }

        return $instant;
    }

    /**
     * The instant of a column that is never empty.
     *
     * @throws DatabaseException when it is empty or unreadable
     */
    public static function requireInstant(?string $stored): DateTimeImmutable
    {
        return self::toInstant($stored) ?? throw new DatabaseException('A required instant is missing from a stored row.');
    }

    /**
     * The night of a column that is never empty.
     *
     * @throws DatabaseException when it is empty or unreadable
     */
    public static function requireNight(?string $stored): DateTimeImmutable
    {
        return self::toNight($stored) ?? throw new DatabaseException('A required date is missing from a stored row.');
    }

    public static function night(DateTimeInterface $night): string
    {
        return Days::key(Days::normalize($night));
    }

    public static function nullableNight(?DateTimeInterface $night): ?string
    {
        return $night === null ? null : self::night($night);
    }

    /**
     * @throws DatabaseException when a stored value is not a date
     */
    public static function toNight(?string $stored): ?DateTimeImmutable
    {
        if ($stored === null || $stored === '') {
            return null;
        }

        $date = substr($stored, 0, 10);
        if (!Days::isValid($date)) {
            throw new DatabaseException(sprintf('Stored date "%s" is unreadable.', $stored));
        }

        return Days::date($date);
    }

    /**
     * @param array<array-key, mixed> $value
     */
    public static function json(array $value): string
    {
        try {
            return json_encode($value, JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        } catch (JsonException $failure) {
            throw new DatabaseException('A value could not be stored as JSON: ' . $failure->getMessage(), 0, $failure);
        }
    }

    /**
     * @return array<array-key, mixed>
     *
     * @throws DatabaseException when the stored text is not a JSON array or object
     */
    public static function toArray(?string $stored): array
    {
        if ($stored === null || $stored === '') {
            return [];
        }

        try {
            $value = json_decode($stored, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException $failure) {
            throw new DatabaseException('A stored JSON value is unreadable: ' . $failure->getMessage(), 0, $failure);
        }
        if (!is_array($value)) {
            throw new DatabaseException('A stored JSON value is not an array.');
        }

        return $value;
    }

    /**
     * Descriptive text cut to the length of its column (in characters), so a long name coming
     * from an external system never makes a statement fail.
     */
    public static function clip(string $value, int $maxLength): string
    {
        return mb_substr($value, 0, $maxLength, 'UTF-8');
    }

    /**
     * A short machine word (a kind, a status, a reason code, a reference) checked against the
     * length of its column: these are never cut, because a cut identifier is a different one.
     *
     * @throws InvalidArgumentException when it is empty, too long or has characters outside letters, digits and "_.:-"
     */
    public static function word(string $value, int $maxLength): string
    {
        if (strlen($value) > $maxLength || preg_match('/^[A-Za-z0-9][A-Za-z0-9_.:-]*$/', $value) !== 1) {
            throw new InvalidArgumentException(sprintf('"%s" is not a valid identifier of at most %d characters.', self::clip($value, 40), $maxLength));
        }

        return $value;
    }

    /**
     * An id given by an external system, checked against the length of its column. It is never
     * cut: a cut reference would point at something else.
     *
     * @throws InvalidArgumentException when it is longer than the column
     */
    public static function reference(string $value, int $maxLength): string
    {
        if (mb_strlen($value, 'UTF-8') > $maxLength) {
            throw new InvalidArgumentException(sprintf('The reference "%s..." is longer than %d characters.', self::clip($value, 20), $maxLength));
        }

        return $value;
    }

    public static function nullableFloat(?string $stored): ?float
    {
        return $stored === null ? null : (float) $stored;
    }

    public static function nullableInt(?string $stored): ?int
    {
        return $stored === null ? null : (int) $stored;
    }
}
