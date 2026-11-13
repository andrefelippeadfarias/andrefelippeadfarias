<?php

declare(strict_types=1);

namespace Ritmo\Admin\Components;

use InvalidArgumentException;

/**
 * Ratio settings (0.75) shown and typed as percentages (75), converted by moving the decimal point
 * in the text (decision D14), so "70" is saved as exactly 0.7.
 */
final class Ratio
{
    private function __construct()
    {
    }

    /**
     * @throws InvalidArgumentException when the text is not a percentage between 0 and 100
     */
    public static function fromPercentText(string $text): float
    {
        $text = str_replace(',', '.', trim($text));
        if (preg_match('/^(\d{1,3})(?:\.(\d{1,4}))?$/', $text, $m) !== 1 || (float) $text > 100) {
            throw new UserFacing(__('Type a percentage between 0 and 100, such as 75.', 'ritmo-revenue'));
        }
        $digits = str_pad($m[1], 3, '0', STR_PAD_LEFT) . ($m[2] ?? '');

        return (float) (substr($digits, 0, 1) . '.' . substr($digits, 1));
    }

    /** 0.75 => "75", 0.075 => "7.5". */
    public static function toPercentText(float $ratio): string
    {
        return rtrim(rtrim(number_format($ratio * 100, 4, '.', ''), '0'), '.');
    }
}
