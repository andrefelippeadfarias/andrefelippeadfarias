<?php

declare(strict_types=1);

namespace Ritmo\Admin\Components;

use Ritmo\Domain\Support\Num;

/**
 * An amount in the property's currency as a person reads it: the currency symbol, a no-break
 * space and the number in the site's locale; whole amounts without decimals, others with two.
 * Decision D5 of the admin spec.
 */
final class Money
{
    private const SYMBOLS = [
        'BRL' => 'R$', 'USD' => 'US$', 'EUR' => '€', 'GBP' => '£', 'ARS' => 'AR$', 'MXN' => 'MX$',
        'CLP' => 'CLP$', 'COP' => 'COL$', 'UYU' => '$U', 'PEN' => 'S/', 'CAD' => 'CA$', 'AUD' => 'A$',
    ];

    private const NO_BREAK_SPACE = "\u{00A0}";

    private const MINUS = "\u{2212}";

    private function __construct()
    {
    }

    public static function format(float $amount, string $currency): string
    {
        $rounded = Num::roundHalfEven($amount, 2);
        $decimals = self::isWhole($rounded) ? 0 : 2;
        $number = number_format_i18n(abs($rounded), $decimals);

        return ($rounded < 0 ? self::MINUS : '') . self::symbol($currency) . self::NO_BREAK_SPACE . $number;
    }

    /**
     * The symbol of an ISO 4217 code; an unknown code is shown as the code itself.
     */
    public static function symbol(string $currency): string
    {
        $code = strtoupper(trim($currency));

        return self::SYMBOLS[$code] ?? $code;
    }

    private static function isWhole(float $amount): bool
    {
        return abs($amount - Num::roundHalfEven($amount)) < 0.0000001;
    }
}
