<?php

declare(strict_types=1);

namespace Ritmo\Application\Rules\Calendar;

use DateTimeImmutable;
use Ritmo\Domain\Support\Days;

/**
 * The holiday periods proposed for some years (spec 05, 5.7.1). Each holiday makes a block of
 * days off, extended over the adjacent weekly days off and, with bridges, over the Monday before
 * a Tuesday holiday and the Friday after a Thursday holiday; blocks that overlap or touch are
 * merged. The nights proposed are from the day before the block to the day before its last day.
 * Pure: no network, no clock.
 */
final class HolidayProposals
{
    private const TUESDAY = 1;
    private const THURSDAY = 3;

    private function __construct()
    {
    }

    /**
     * @param list<int> $years
     *
     * @return list<HolidayProposal> by first night
     */
    public static function forYears(HolidayList $list, array $years, bool $bridges): array
    {
        $blocks = [];
        foreach (array_unique($years) as $year) {
            foreach ($list->datesIn($year) as $holiday) {
                [$first, $last] = self::block($holiday['date'], $list->daysOff, $bridges && $list->bridges);
                $blocks[] = ['first' => $first, 'last' => $last, 'holidays' => [$holiday]];
            }
        }
        usort($blocks, static fn (array $a, array $b): int => $a['first'] <=> $b['first']);

        $merged = [];
        foreach ($blocks as $block) {
            $previous = $merged === [] ? null : $merged[count($merged) - 1];
            if ($previous !== null && $block['first'] <= Days::add($previous['last'], 1)) {
                $merged[count($merged) - 1] = ['first' => $previous['first'], 'last' => max($previous['last'], $block['last']), 'holidays' => [...$previous['holidays'], ...$block['holidays']]];
            } else {
                $merged[] = $block;
            }
        }

        return array_map(static fn (array $b): HolidayProposal => new HolidayProposal(
            $b['holidays'],
            $b['first'],
            $b['last'],
            array_filter($b['holidays'], static fn (array $h): bool => !in_array(Days::weekday($h['date']), $list->daysOff, true)) === [],
        ), $merged);
    }

    /**
     * @param list<int> $daysOff
     *
     * @return array{0: DateTimeImmutable, 1: DateTimeImmutable}
     */
    private static function block(DateTimeImmutable $holiday, array $daysOff, bool $bridges): array
    {
        $first = $holiday;
        $last = $holiday;
        if ($bridges && Days::weekday($holiday) === self::TUESDAY) {
            $first = Days::add($holiday, -1);
        }
        if ($bridges && Days::weekday($holiday) === self::THURSDAY) {
            $last = Days::add($holiday, 1);
        }
        while (in_array(Days::weekday(Days::add($first, -1)), $daysOff, true)) {
            $first = Days::add($first, -1);
        }
        while (in_array(Days::weekday(Days::add($last, 1)), $daysOff, true)) {
            $last = Days::add($last, 1);
        }

        return [$first, $last];
    }
}
