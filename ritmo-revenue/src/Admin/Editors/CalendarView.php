<?php

declare(strict_types=1);

namespace Ritmo\Admin\Editors;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Ritmo\Admin\Components\Money;
use Ritmo\Application\Rules\Calendar\HolidayList;
use Ritmo\Application\Rules\Calendar\HolidayProposal;
use Ritmo\Application\Rules\Calendar\HolidayProposals;
use Ritmo\Domain\Config\Schema;
use Ritmo\Domain\Support\Days;

/**
 * The calendar tab (spec 05, 5.7): weekend nights, three months with each night's marks in
 * words (weekend night, holiday period, season and its minimum stay, package), the holiday
 * periods in the order that decides overlaps, seasons and packages, and the import of national
 * holidays from the lists shipped with the plugin (no network).
 */
final class CalendarView
{
    private const MONTHS = 3;

    private function __construct()
    {
    }

    /**
     * @param array<string, mixed>                                              $rules
     * @param array{country: string, years: list<int>, bridges: bool}|null      $import  the preview asked for
     *
     * @return array<string, mixed>
     */
    public static function of(EditorPage $page, array $rules, DateTimeImmutable $firstMonth, ?array $import, bool $readOnly): array
    {
        $schema = Schema::ruleSet();
        $ctx = new FieldContext($schema, FormScope::tab(RulesLayout::CALENDAR), EditorServices::instance()->drafts()->inForce(), $page->roomNames(), $page->currency(), FormErrors::take(), $readOnly);
        $holidays = array_values(array_filter((array) ($rules['calendar']['holidays'] ?? []), 'is_array'));
        $catalogue = EditorServices::instance()->holidays();
        try {
            $countries = array_map(static fn (string $c): array => ['code' => $c, 'name' => HolidayList::name($catalogue->load($c)->names, get_user_locale())], $catalogue->countries());
            $loadFailed = false;
        } catch (InvalidArgumentException) {
            $countries = [];
            $loadFailed = true;
        }
        $year = (int) EditorServices::instance()->services()->clock()->today($page->property?->timezone ?? 'UTC')->format('Y');

        return [
            'weekend' => self::weekendNode(FieldView::nodes(['calendar'], $rules, $ctx)[0]['children'][0] ?? null),
            'months' => self::months($rules, $firstMonth),
            'previous' => Days::key($firstMonth->modify('-' . self::MONTHS . ' months')),
            'next' => Days::key($firstMonth->modify('+' . self::MONTHS . ' months')),
            'holidays' => self::holidays($holidays, $page),
            'seasons_and_packages' => FieldView::nodes(['stay'], $rules, $ctx)[0]['children'] ?? [],
            'countries' => $countries,
            'load_failed' => $loadFailed,
            'years' => [$year, $year + 1, $year + 2],
            'import' => $import === null ? null : self::proposals($import, $holidays),
            'read_only' => $readOnly,
        ];
    }

    /**
     * @param array<string, mixed> $rules
     *
     * @return list<array{key: string, caption: string, weeks: list<list<array{day: int, night: string, marks: list<string>, kinds: list<string>}|null>>}>
     */
    private static function months(array $rules, DateTimeImmutable $first): array
    {
        $weekend = array_map('intval', (array) ($rules['calendar']['weekend_days'] ?? []));
        $months = [];
        for ($m = 0; $m < self::MONTHS; ++$m) {
            $start = $first->modify('+' . $m . ' months');
            $days = (int) $start->format('t');
            $weeks = [];
            $week = array_fill(0, Days::weekday($start), null);
            for ($d = 1; $d <= $days; ++$d) {
                $night = Days::date($start->format('Y-m-') . sprintf('%02d', $d));
                [$marks, $kinds] = self::marks($night, $rules, $weekend);
                $week[] = ['day' => $d, 'night' => Days::key($night), 'marks' => $marks, 'kinds' => $kinds];
                if (count($week) === 7) {
                    $weeks[] = $week;
                    $week = [];
                }
            }
            if ($week !== []) {
                $weeks[] = array_pad($week, 7, null);
            }
            $months[] = ['key' => $start->format('Y-m'), 'caption' => wp_date('F Y', $start->getTimestamp(), new DateTimeZone('UTC')), 'weeks' => $weeks];
        }

        return $months;
    }

    /**
     * The weekend-days field without the schema's help, which names the days by number: the tab
     * explains the nights in words under the boxes.
     *
     * @param array<string, mixed>|null $node
     *
     * @return array<string, mixed>|null
     */
    private static function weekendNode(?array $node): ?array
    {
        return $node === null ? null : ['help' => ''] + $node;
    }

    /**
     * @param array<string, mixed> $rules
     * @param list<int>            $weekend
     *
     * @return array{0: list<string>, 1: list<string>}
     */
    private static function marks(DateTimeImmutable $night, array $rules, array $weekend): array
    {
        $marks = [];
        $kinds = [];
        $key = Days::key($night);
        if (in_array(Days::weekday($night), $weekend, true)) {
            $marks[] = __('Weekend', 'ritmo-revenue');
            $kinds[] = 'weekend';
        }
        foreach ((array) ($rules['calendar']['holidays'] ?? []) as $period) {
            if (is_array($period) && ($period['start'] ?? '') <= $key && $key <= ($period['end'] ?? '')) {
                $marks[] = (string) $period['name'];
                $kinds[] = 'holiday';
                break;
            }
        }
        foreach ((array) ($rules['stay']['seasons'] ?? []) as $season) {
            $weekdays = (array) ($season['weekdays'] ?? []);
            if (is_array($season) && ($season['start'] ?? '') <= $key && $key <= ($season['end'] ?? '') && ($weekdays === [] || in_array(Days::weekday($night), $weekdays, true))) {
                /* translators: 1: season name, 2: minimum nights */
                $marks[] = sprintf(__('%1$s (min %2$d)', 'ritmo-revenue'), (string) $season['name'], (int) ($season['min_stay'] ?? 1));
                $kinds[] = 'season';
            }
        }
        foreach ((array) ($rules['stay']['packages'] ?? []) as $package) {
            foreach ((array) ($package['nights'] ?? []) as $packageNight) {
                if (is_array($packageNight) && ($packageNight['date'] ?? '') === $key) {
                    $marks[] = (string) $package['name'];
                    $kinds[] = 'package';
                    break;
                }
            }
        }

        return [$marks, $kinds];
    }

    /**
     * @param list<array<string, mixed>> $holidays
     *
     * @return list<array<string, mixed>>
     */
    private static function holidays(array $holidays, EditorPage $page): array
    {
        $rows = [];
        foreach ($holidays as $index => $period) {
            $start = (string) ($period['start'] ?? '');
            $end = (string) ($period['end'] ?? '');
            $overlap = null;
            foreach (array_slice($holidays, 0, $index) as $earlier) {
                if (($earlier['start'] ?? '') <= $end && $start <= ($earlier['end'] ?? '')) {
                    $overlap = (string) ($earlier['name'] ?? '');
                    break;
                }
            }
            $rows[] = [
                'index' => $index,
                'name' => (string) ($period['name'] ?? ''),
                'nights' => Days::isValid($start) && Days::isValid($end) ? Days::diff(Days::date($start), Days::date($end)) + 1 : 0,
                'range' => self::range($start, $end),
                'type' => ChoiceLabels::of('calendar.holidays.*.night_type', (string) ($period['night_type'] ?? 'holiday')),
                'min_stay' => $period['min_stay'] ?? null,
                'floor' => isset($period['floor']) ? Money::format((float) $period['floor'], $page->currency()) : '',
                'overlap' => $overlap,
                'last' => $index === count($holidays) - 1,
            ];
        }

        return $rows;
    }

    /**
     * @param array{country: string, years: list<int>, bridges: bool} $import
     * @param list<array<string, mixed>>                              $holidays
     *
     * @return array<string, mixed>
     */
    private static function proposals(array $import, array $holidays): array
    {
        try {
            $list = EditorServices::instance()->holidays()->load($import['country']);
        } catch (InvalidArgumentException) {
            return ['error' => true, 'rows' => []];
        }
        $locale = get_user_locale();
        $rows = array_map(static function (HolidayProposal $p) use ($holidays, $locale): array {
            $p = $p->markListed($holidays);

            return [
                'key' => $p->key(),
                'name' => $p->name($locale),
                'dates' => implode(', ', array_map(static fn (array $h): string => wp_date('l, j M Y', $h['date']->getTimestamp(), new DateTimeZone('UTC')), $p->holidays)),
                'optional' => array_filter($p->holidays, static fn (array $h): bool => $h['optional']) !== [],
                'range' => self::range(Days::key($p->firstNight()), Days::key($p->lastNight())),
                'nights' => $p->nights(),
                'ticked' => $p->ticked(),
                'listed' => $p->alreadyListed,
                'weekend' => $p->onWeekend,
            ];
        }, HolidayProposals::forYears($list, $import['years'], $import['bridges']));

        return ['error' => false, 'rows' => $rows] + $import;
    }

    private static function range(string $start, string $end): string
    {
        if (!Days::isValid($start) || !Days::isValid($end)) {
            return '';
        }
        $utc = new DateTimeZone('UTC');
        $first = wp_date('j M Y', Days::date($start)->getTimestamp(), $utc);

        return $start === $end ? $first : $first . ' – ' . wp_date('j M Y', Days::date($end)->getTimestamp(), $utc);
    }
}
