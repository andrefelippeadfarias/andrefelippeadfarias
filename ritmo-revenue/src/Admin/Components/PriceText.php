<?php

declare(strict_types=1);

namespace Ritmo\Admin\Components;

use Ritmo\Domain\Support\Num;

/**
 * The only way a screen states a floor, a ceiling, a listing value or a price change: money first,
 * then the percent in words. Each method returns plain texts (the template escapes them):
 * "text" the amount or "before → after", "note" the percent part, "sr" the full sentence for
 * screen readers. A missing value is "—" read as "not set".
 */
final class PriceText
{
    private const ARROW = ' → ';

    private function __construct()
    {
    }

    /**
     * @return array{text: string, note: string, sr: string}
     */
    public static function amount(?float $amount, string $currency): array
    {
        if ($amount === null) {
            return self::missing();
        }
        $text = Money::format($amount, $currency);

        return ['text' => $text, 'note' => '', 'sr' => $text];
    }

    /**
     * "R$ 480" with the note "20% below the base of R$ 600".
     *
     * @return array{text: string, note: string, sr: string}
     */
    public static function withBase(float $amount, int $percent, float $base, string $currency): array
    {
        $text = Money::format($amount, $currency);
        /* translators: 1: "20% below the base" or "at the base", 2: the base amount */
        $note = sprintf(__('%1$s of %2$s', 'ritmo-revenue'), Percent::ofBase($percent), Money::format($base, $currency));

        return ['text' => $text, 'note' => $note, 'sr' => $text . ' (' . $note . ')'];
    }

    /**
     * A whole percentage of the base as the amount the pricing system applies.
     *
     * @return array{text: string, note: string, sr: string}
     */
    public static function fromPercent(?int $percent, float $base, string $currency): array
    {
        if ($percent === null || $base <= 0) {
            return self::missing();
        }

        return self::withBase(Num::percentToAmount($percent, $base), $percent, $base, $currency);
    }

    /**
     * "R$ 400 → R$ 380" with the note "−5.0%".
     *
     * @return array{text: string, note: string, sr: string}
     */
    public static function change(?float $before, ?float $after, string $currency): array
    {
        if ($before === null && $after === null) {
            return self::missing();
        }
        $from = $before === null ? '—' : Money::format($before, $currency);
        $to = $after === null ? '—' : Money::format($after, $currency);
        $note = $before !== null && $after !== null ? Percent::change($before, $after) : '';
        $text = $from . self::ARROW . $to;
        /* translators: 1: amount before, 2: amount after */
        $sr = sprintf(__('from %1$s to %2$s', 'ritmo-revenue'), $before === null ? __('not set', 'ritmo-revenue') : $from, $after === null ? __('not set', 'ritmo-revenue') : $to);

        return ['text' => $text, 'note' => $note, 'sr' => $note === '' ? $sr : $sr . ' (' . $note . ')'];
    }

    /**
     * @return array{text: string, note: string, sr: string}
     */
    private static function missing(): array
    {
        return ['text' => '—', 'note' => '', 'sr' => __('not set', 'ritmo-revenue')];
    }
}
