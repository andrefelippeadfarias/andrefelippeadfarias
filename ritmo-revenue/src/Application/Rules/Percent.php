<?php

declare(strict_types=1);

namespace Ritmo\Application\Rules;

use InvalidArgumentException;

/**
 * Shares (0 to 1) shown and typed as percents by moving the decimal point in the text, never by
 * multiplying floats: "70" saves exactly 0.7 and 0.075 shows as "7.5" (spec 05, D14).
 */
final class Percent
{
    private const NUMBER = '/^(\d{1,9})(?:[.,](\d{1,6}))?$/';

    private function __construct()
    {
    }

    /**
     * "70" → 0.7, "7.5" → 0.075, "0,5" → 0.005.
     *
     * @throws InvalidArgumentException when the text is not a plain positive decimal number
     */
    public static function toRatio(string $text): float
    {
        if (preg_match(self::NUMBER, trim($text), $m) !== 1) {
            throw new InvalidArgumentException('A percent is a number such as 70 or 7.5.');
        }
        $whole = ltrim($m[1], '0');
        $digits = $whole . ($m[2] ?? '');
        $scale = strlen($m[2] ?? '') + 2;
        $digits = str_pad($digits === '' ? '0' : $digits, $scale + 1, '0', STR_PAD_LEFT);

        return (float) (substr($digits, 0, -$scale) . '.' . substr($digits, -$scale));
    }

    /**
     * 0.7 → "70", 0.075 → "7.5", 1.0 → "100".
     */
    public static function fromRatio(float $ratio): string
    {
        $text = rtrim(rtrim(sprintf('%.8F', $ratio), '0'), '.');
        $negative = str_starts_with($text, '-');
        $text = ltrim($text, '-');
        [$whole, $fraction] = array_pad(explode('.', $text), 2, '');
        $fraction = str_pad($fraction, 2, '0');
        $shifted = ltrim($whole . substr($fraction, 0, 2), '0');
        $rest = rtrim(substr($fraction, 2), '0');

        return ($negative ? '-' : '') . ($shifted === '' ? '0' : $shifted) . ($rest === '' ? '' : '.' . $rest);
    }
}
