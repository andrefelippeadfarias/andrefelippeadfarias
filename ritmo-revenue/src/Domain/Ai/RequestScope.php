<?php

declare(strict_types=1);

namespace Ritmo\Domain\Ai;

use DateTimeImmutable;
use Ritmo\Domain\Support\Days;

/**
 * What the person's own words allow a draft to cover: the rooms and the nights they named.
 *
 * A draft may name every room ("*") only when the request names no room, or says all of them;
 * when it names rooms, the draft keeps to them. The nights must be anchored in the request: a
 * date it wrote (24/12, 2026-12-24, "24 de dezembro"), a month it named ("dezembro") or a
 * holiday period of the calendar named by its name; a request with none of these may cover at
 * most MAX_UNANCHORED_NIGHTS nights. A person still reviews every field in the order form.
 */
final class RequestScope
{
    public const MAX_UNANCHORED_NIGHTS = 31;

    /** Words that say "every room". */
    private const ALL_ROOMS = [
        'todos os quartos', 'todas as acomodacoes', 'todas as suites', 'todos os apartamentos', 'todas as unidades',
        'cada quarto', 'all rooms', 'all the rooms', 'every room', 'each room', 'all room types',
    ];

    private const MONTHS = [
        'janeiro' => 1, 'fevereiro' => 2, 'marco' => 3, 'abril' => 4, 'maio' => 5, 'junho' => 6, 'julho' => 7,
        'agosto' => 8, 'setembro' => 9, 'outubro' => 10, 'novembro' => 11, 'dezembro' => 12,
        'january' => 1, 'february' => 2, 'march' => 3, 'april' => 4, 'june' => 6, 'july' => 7,
        'august' => 8, 'september' => 9, 'october' => 10, 'november' => 11, 'december' => 12,
    ];

    private function __construct()
    {
    }

    /** Lower case, no accents, words split by single spaces. */
    public static function words(string $text): string
    {
        $text = strtr(mb_strtolower($text), [
            'á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a', 'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
            'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i', 'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o',
            'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ç' => 'c', 'ñ' => 'n', 'º' => 'o', 'ª' => 'a',
        ]);

        return trim((string) preg_replace('/[^a-z0-9]+/', ' ', $text));
    }

    /** Whether the words of $name appear, as whole words, in $text. */
    public static function names(string $text, string $name): bool
    {
        $words = self::words($name);

        return $words !== '' && str_contains(' ' . self::words($text) . ' ', ' ' . $words . ' ');
    }

    public static function saysAllRooms(string $request): bool
    {
        foreach (self::ALL_ROOMS as $words) {
            if (self::names($request, $words)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The room codes the request names, by code or by label.
     *
     * @param array<string, string> $labels room code => label
     * @param list<string>          $codes
     *
     * @return list<string>
     */
    public static function namedRooms(string $request, array $codes, array $labels): array
    {
        $named = [];
        foreach ($codes as $code) {
            if (self::names($request, $code) || (isset($labels[$code]) && mb_strlen(self::words($labels[$code])) >= 3 && self::names($request, $labels[$code]))) {
                $named[] = $code;
            }
        }

        return $named;
    }

    /**
     * Whether the first and the last night of a draft are anchored in the request.
     *
     * @param list<array{name: string, start: string, end: string}> $periods the holiday periods of the calendar
     */
    public static function allowsNights(string $request, DateTimeImmutable $from, DateTimeImmutable $to, DateTimeImmutable $today, DateTimeImmutable $lastNight, array $periods): bool
    {
        $anchors = self::anchors($request, $today, $lastNight, $periods);
        if ($anchors === []) {
            return Days::diff($from, $to) < self::MAX_UNANCHORED_NIGHTS;
        }

        return self::inside($from, $anchors) && self::inside($to, $anchors);
    }

    /**
     * The spans of nights the request names (first and last night of each).
     *
     * @param list<array{name: string, start: string, end: string}> $periods
     *
     * @return list<array{0: DateTimeImmutable, 1: DateTimeImmutable}>
     */
    private static function anchors(string $request, DateTimeImmutable $today, DateTimeImmutable $lastNight, array $periods): array
    {
        $anchors = [];
        $words = ' ' . self::words($request) . ' ';
        foreach (self::writtenDays($request) as [$year, $month, $day]) {
            foreach (self::occurrences($year, $month, $day, $today, $lastNight) as $date) {
                $anchors[] = [$date, $date];
            }
        }
        foreach (self::MONTHS as $name => $month) {
            if (!str_contains($words, ' ' . $name . ' ')) {
                continue;
            }
            for ($year = (int) $today->format('Y'); $year <= (int) $lastNight->format('Y'); $year++) {
                $first = Days::date(sprintf('%04d-%02d-01', $year, $month));
                $anchors[] = [$first, Days::date($first->format('Y-m-t'))];
            }
        }
        foreach ($periods as $period) {
            if (mb_strlen(self::words($period['name'])) >= 3 && self::names($request, $period['name']) && Days::isValid($period['start']) && Days::isValid($period['end'])) {
                $anchors[] = [Days::date($period['start']), Days::date($period['end'])];
            }
        }

        return $anchors;
    }

    /**
     * @param list<array{0: DateTimeImmutable, 1: DateTimeImmutable}> $anchors
     */
    private static function inside(DateTimeImmutable $night, array $anchors): bool
    {
        foreach ($anchors as [$first, $last]) {
            if (Days::diff($first, $night) >= 0 && Days::diff($night, $last) >= 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * The days the request writes: [year or 0, month, day].
     *
     * @return list<array{0: int, 1: int, 2: int}>
     */
    private static function writtenDays(string $request): array
    {
        $days = [];
        if (preg_match_all('/\b(\d{4})-(\d{2})-(\d{2})\b/', $request, $m, PREG_SET_ORDER) > 0) {
            foreach ($m as $match) {
                $days[] = [(int) $match[1], (int) $match[2], (int) $match[3]];
            }
        }
        if (preg_match_all('#(?<![\d/-])(\d{1,2})/(\d{1,2})(?:/(\d{2}|\d{4}))?(?![\d/])#', $request, $m, PREG_SET_ORDER) > 0) {
            foreach ($m as $match) {
                $year = isset($match[3]) && $match[3] !== '' ? (int) $match[3] : 0;
                $days[] = [$year > 0 && $year < 100 ? 2000 + $year : $year, (int) $match[2], (int) $match[1]];
            }
        }
        $words = self::words($request);
        $months = implode('|', array_keys(self::MONTHS));
        if (preg_match_all('/\b(\d{1,2})(?: o)?(?: de)? (' . $months . ')\b/', $words, $m, PREG_SET_ORDER) > 0) {
            foreach ($m as $match) {
                $days[] = [0, self::MONTHS[$match[2]], (int) $match[1]];
            }
        }
        if (preg_match_all('/\b(' . $months . ') (\d{1,2})(?: st| nd| rd| th)?\b/', $words, $m, PREG_SET_ORDER) > 0) {
            foreach ($m as $match) {
                $days[] = [0, self::MONTHS[$match[1]], (int) $match[2]];
            }
        }

        return $days;
    }

    /**
     * The nights from today to the last night that fall on that day (of that year, when given).
     *
     * @return list<DateTimeImmutable>
     */
    private static function occurrences(int $year, int $month, int $day, DateTimeImmutable $today, DateTimeImmutable $lastNight): array
    {
        $out = [];
        $years = $year > 0 ? [$year] : range((int) $today->format('Y'), (int) $lastNight->format('Y'));
        foreach ($years as $y) {
            if (!checkdate($month, $day, $y)) {
                continue;
            }
            $out[] = Days::date(sprintf('%04d-%02d-%02d', $y, $month, $day));
        }

        return $out;
    }
}
