<?php

declare(strict_types=1);

namespace Ritmo\Domain\Ai;

/**
 * The model only words what the plugin computed: every number in its text must be a number of
 * its input. A number is read both ways (1.234,50 and 1,234.50; 45,5 and 45.5), so the language of
 * the text does not matter; trailing zeros do not matter either (65.20 is 65.2). Dates of the
 * input count by their parts (2026-12-24 gives 2026, 12 and 24).
 */
final class NumberCheck
{
    private const TOKEN = '/\d+(?:[.,]\d+)*/';

    private const TOLERANCE = 0.000001;

    private function __construct()
    {
    }

    /**
     * The numbers of the output that are not in the input, as written in the output.
     *
     * @return list<string>
     */
    public static function unknown(string $output, string $input): array
    {
        $allowed = [];
        foreach (self::tokens($input) as $token) {
            foreach ([...self::values($token), ...self::parts($token)] as $value) {
                $allowed[] = $value;
            }
        }
        $unknown = [];
        foreach (self::tokens($output) as $token) {
            $values = self::values($token);
            $known = $values === []
                ? array_filter(self::parts($token), static fn (float $part): bool => !self::among($part, $allowed)) === []
                : array_filter($values, static fn (float $value): bool => self::among($value, $allowed)) !== [];
            if (!$known) {
                $unknown[] = $token;
            }
        }

        return array_values(array_unique($unknown));
    }

    /**
     * Whether a value is one of the numbers of a text (an amount or a percentage the person wrote).
     */
    public static function mentions(string $text, float $value): bool
    {
        foreach (self::tokens($text) as $token) {
            if (self::among($value, self::values($token))) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<float> $values
     */
    private static function among(float $value, array $values): bool
    {
        foreach ($values as $known) {
            if (abs($known - $value) <= self::TOLERANCE * max(1.0, abs($known))) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private static function tokens(string $text): array
    {
        preg_match_all(self::TOKEN, $text, $matches);

        return array_values(array_filter(array_map(static fn (string $t): string => rtrim($t, '.,'), $matches[0]), static fn (string $t): bool => $t !== ''));
    }

    /**
     * The values a written number may stand for, under both decimal conventions; none for a
     * token that reads under neither (a date written with dots).
     *
     * @return list<float>
     */
    private static function values(string $token): array
    {
        $values = [];
        foreach ([['.', ','], [',', '.']] as [$thousands, $decimal]) {
            $value = self::read($token, $thousands, $decimal);
            if ($value !== null) {
                $values[] = $value;
            }
        }

        return array_values(array_unique($values, SORT_REGULAR));
    }

    /**
     * The numbers between the dots and commas of a token (the day, month and year of a date).
     *
     * @return list<float>
     */
    private static function parts(string $token): array
    {
        return array_map(static fn (string $part): float => (float) $part, array_values(array_filter(preg_split('/[.,]/', $token) ?: [], static fn (string $p): bool => $p !== '')));
    }

    private static function read(string $token, string $thousands, string $decimal): ?float
    {
        $parts = explode($decimal, $token);
        if (count($parts) > 2) {
            return null;
        }
        $whole = $parts[0];
        if (str_contains($whole, $thousands) && preg_match('/^\d{1,3}(' . preg_quote($thousands, '/') . '\d{3})+$/', $whole) !== 1) {
            return null;
        }
        $number = str_replace($thousands, '', $whole) . (isset($parts[1]) ? '.' . $parts[1] : '');

        return is_numeric($number) ? (float) $number : null;
    }
}
