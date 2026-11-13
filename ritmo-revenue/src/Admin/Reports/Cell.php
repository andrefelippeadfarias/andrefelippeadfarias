<?php

declare(strict_types=1);

namespace Ritmo\Admin\Reports;

use Ritmo\Admin\Components\Money;
use Ritmo\Admin\Components\NightDate;
use Ritmo\Admin\Components\Percent;
use Ritmo\Domain\Support\Days;
use Ritmo\Domain\Support\Num;

/**
 * What a column holds and how a person reads it: text, a count, money, a share kept as a fraction
 * (0.226 reads "23%"), a percentage (18.0 reads "18%") or a change kept as a fraction ("+6.4%").
 */
enum Cell: string
{
    case Text = 'text';
    /** A night or the Monday of a week, kept as "YYYY-MM-DD" (ISO in the CSV). */
    case Date = 'date';
    case Count = 'count';
    case Money = 'money';
    case Fraction = 'fraction';
    case Percent = 'percent';
    case Change = 'change';

    public function format(string|int|float|null $value, string $currency): string
    {
        if ($value === null || $value === '') {
            return '—';
        }

        return match ($this) {
            self::Text => (string) $value,
            self::Date => Days::isValid((string) $value) ? NightDate::short(Days::date((string) $value)) : (string) $value,
            self::Count => number_format_i18n((float) $value),
            self::Money => Money::format((float) $value, $currency),
            self::Fraction => Percent::share(100 * (float) $value),
            self::Percent => Percent::share((float) $value),
            self::Change => Percent::change(1.0, 1.0 + (float) $value),
        };
    }

    /**
     * The value as the CSV carries it: numbers as numbers (fractions as percentages with two
     * decimals, so a spreadsheet reads them the way the screen shows them), texts as texts.
     */
    public function csv(string|int|float|null $value): string|int|float|null
    {
        return match (true) {
            $value === null || $value === '' => null,
            $this === self::Fraction, $this === self::Change => Num::roundHalfEven(100 * (float) $value, 2),
            $this === self::Text, $this === self::Date => (string) $value,
            default => $value,
        };
    }

    public function isMoney(): bool
    {
        return $this === self::Money;
    }
}
