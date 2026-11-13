<?php

declare(strict_types=1);

namespace Ritmo\Admin\Reports;

/**
 * The geometry of a bar chart drawn as inline SVG by the template (no library): one bar per value
 * with its label and its value written beside it. The table next to it is the accessible source,
 * so the drawing is aria-hidden; colours come from the reports stylesheet.
 */
final class BarChart
{
    public const WIDTH = 640;
    private const BAR = 22;
    private const GAP = 8;
    private const LABEL = 150;

    private function __construct()
    {
    }

    /**
     * @param list<array{label: string, value: float, text: string}> $bars value between 0 and 1
     *
     * @return array{height: int, bars: list<array{label: string, text: string, x: int, y: int, width: int, textY: int, valueX: int}>}|null
     */
    public static function of(array $bars): ?array
    {
        if ($bars === []) {
            return null;
        }
        $room = self::WIDTH - self::LABEL - 60;
        $out = [];
        foreach ($bars as $i => $bar) {
            $y = $i * (self::BAR + self::GAP);
            $length = max(1, (int) round($room * max(0.0, min(1.0, $bar['value']))));
            $out[] = ['label' => $bar['label'], 'text' => $bar['text'], 'x' => self::LABEL, 'y' => $y, 'width' => $length, 'textY' => $y + 16, 'valueX' => self::LABEL + $length + 6];
        }

        return ['height' => count($bars) * (self::BAR + self::GAP), 'bars' => $out];
    }
}
