<?php

declare(strict_types=1);

namespace Ritmo\Domain\Ai;

use Closure;
use Ritmo\Domain\Report\Weekly\MadeWeek;
use Ritmo\Domain\Report\Weekly\Moment;
use Ritmo\Domain\Report\Weekly\ShareRow;
use Ritmo\Domain\Report\Weekly\StayWeek;
use Ritmo\Domain\Report\Weekly\WeeklyReport;
use Ritmo\Domain\Support\Days;

/**
 * The facts of the weekly panel the assistant may word, for one property: the bookings made in
 * the last complete week and in the current one, the stays of this week and the next, the month
 * against its minimum and the main channels of the last 28 days. Only numbers the report already
 * computed; nothing about a guest (the report has no guest field).
 */
final class WeeklyFacts
{
    public const COUNT = 'count';
    public const MONEY = 'money';
    public const SHARE = 'share';
    public const DATE = 'date';

    private const CHANNELS = 3;

    private function __construct()
    {
    }

    /**
     * @return list<array{label: string, value: int|float|string, kind: string}>
     */
    public static function of(WeeklyReport $report, string $propertyId): array
    {
        $facts = [['label' => 'Today', 'value' => Days::key($report->today), 'kind' => self::DATE]];
        $made = array_values(array_filter($report->made, static fn (MadeWeek $w): bool => $w->propertyId === $propertyId));
        $complete = array_values(array_filter($made, static fn (MadeWeek $w): bool => $w->complete));
        $last = $complete[count($complete) - 1] ?? null;
        $current = null;
        foreach ($made as $week) {
            if (!$week->complete) {
                $current = $week;
            }
        }
        foreach (['Last complete week' => $last, 'This week so far' => $current] as $name => $week) {
            if ($week !== null) {
                $facts = [...$facts, ...self::made($name, $week)];
            }
        }

        $stays = array_values(array_filter($report->stays, static fn (StayWeek $w): bool => $w->propertyId === $propertyId && $w->moment !== Moment::Past));
        foreach (array_slice($stays, 0, 2) as $index => $stay) {
            $facts = [...$facts, ...self::stay($index === 0 && $stay->moment === Moment::Current ? 'Stays this week' : 'Stays of the week from ' . Days::key($stay->monday), $stay)];
        }

        foreach ($report->goals as $month => $byProperty) {
            $stays = $byProperty[$propertyId] ?? null;
            if ($stays === null) {
                continue;
            }
            $facts = [...$facts, ...self::filled([
                [$month . ' revenue', $stays->revenue, self::MONEY],
                [$month . ' occupancy', $stays->occupancy, self::SHARE],
                [$month . ' average daily rate', $stays->adr, self::MONEY],
                [$month . ' owner minimum', $stays->minimum, self::MONEY],
                [$month . ' gap to the minimum', $stays->gapToMinimum, self::MONEY],
                [$month . ' room nights still free', $stays->freeNights, self::COUNT],
                [$month . ' daily rate needed on the free nights', $stays->adrNeeded, self::MONEY],
            ])];
            break;
        }

        $channels = array_values(array_filter($report->channels, static fn (ShareRow $r): bool => $r->propertyId === $propertyId));
        usort($channels, static fn (ShareRow $a, ShareRow $b): int => $b->share <=> $a->share);
        foreach (array_slice($channels, 0, self::CHANNELS) as $row) {
            $facts = [...$facts, ...self::filled([
                ['Channel ' . $row->key . ' share of revenue, last 28 days', $row->share, self::SHARE],
                ['Channel ' . $row->key . ' revenue, last 28 days', $row->totals->revenue, self::MONEY],
            ])];
        }

        return $facts;
    }

    /**
     * The facts with their values written by the reader's formatter (money with the currency,
     * shares as percentages, dates and counts in the site's language).
     *
     * @param list<array{label: string, value: int|float|string, kind: string}> $facts
     * @param Closure(int|float|string, string): string                       $format value and kind => text
     *
     * @return list<array{label: string, value: string}>
     */
    public static function formatted(array $facts, Closure $format): array
    {
        return array_map(static fn (array $f): array => ['label' => $f['label'], 'value' => $format($f['value'], $f['kind'])], $facts);
    }

    /**
     * @return list<array{label: string, value: int|float|string, kind: string}>
     */
    private static function made(string $name, MadeWeek $week): array
    {
        return self::filled([
            [$name . ' (from ' . Days::key($week->monday) . '): bookings made', $week->totals->active, self::COUNT],
            [$name . ': room nights booked', $week->totals->roomNights, self::COUNT],
            [$name . ': revenue booked', $week->totals->revenue, self::MONEY],
            [$name . ': average daily rate booked', $week->totals->adr, self::MONEY],
            [$name . ': cancellations', $week->cancellations, self::COUNT],
            [$name . ': revenue cancelled', $week->cancelledRevenue, self::MONEY],
        ]);
    }

    /**
     * @return list<array{label: string, value: int|float|string, kind: string}>
     */
    private static function stay(string $name, StayWeek $stay): array
    {
        return self::filled([
            [$name . ': occupancy', $stay->occupancy, self::SHARE],
            [$name . ': room nights sold', $stay->roomNights, self::COUNT],
            [$name . ': revenue', $stay->revenue, self::MONEY],
            [$name . ': average daily rate', $stay->adr, self::MONEY],
        ]);
    }

    /**
     * @param list<array{0: string, 1: int|float|null, 2: string}> $rows
     *
     * @return list<array{label: string, value: int|float, kind: string}>
     */
    private static function filled(array $rows): array
    {
        $out = [];
        foreach ($rows as [$label, $value, $kind]) {
            if ($value !== null) {
                $out[] = ['label' => $label, 'value' => $value, 'kind' => $kind];
            }
        }

        return $out;
    }
}
