<?php

declare(strict_types=1);

namespace Ritmo\Application\Rules\Calendar;

use DateTimeImmutable;
use Ritmo\Domain\Support\Days;

/**
 * One proposed holiday period: the holidays it comes from, their dates, the days off they make
 * (with weekends and bridges) and the nights a guest stays for them, from the evening before the
 * first day off to the night before the last.
 */
final class HolidayProposal
{
    /**
     * @param list<array{id: string, date: DateTimeImmutable, optional: bool, names: array<string, string>}> $holidays
     * @param bool $onWeekend   every holiday of it falls on a day off anyway (proposed unticked)
     * @param bool $alreadyListed a period of the rules already covers one of its nights (proposed unticked)
     */
    public function __construct(
        public readonly array $holidays,
        public readonly DateTimeImmutable $firstDayOff,
        public readonly DateTimeImmutable $lastDayOff,
        public readonly bool $onWeekend,
        public readonly bool $alreadyListed = false,
    ) {
    }

    public function firstNight(): DateTimeImmutable
    {
        return Days::add($this->firstDayOff, -1);
    }

    public function lastNight(): DateTimeImmutable
    {
        return Days::add($this->lastDayOff, -1);
    }

    public function nights(): int
    {
        return Days::diff($this->firstNight(), $this->lastNight()) + 1;
    }

    public function ticked(): bool
    {
        return !$this->onWeekend && !$this->alreadyListed;
    }

    /**
     * "Carnival Monday + Carnival Tuesday" in a language.
     */
    public function name(string $locale): string
    {
        return implode(' + ', array_map(static fn (array $h): string => HolidayList::name($h['names'], $locale), $this->holidays));
    }

    /**
     * A stable key of the proposal (its first night and its holidays), for the form.
     */
    public function key(): string
    {
        return Days::key($this->firstNight()) . ':' . implode('+', array_column($this->holidays, 'id'));
    }

    /**
     * The calendar.holidays item it becomes: name, nights, holiday nights, no stay or floor of its own.
     *
     * @return array{name: string, start: string, end: string, night_type: string, min_stay: null, floor: null}
     */
    public function toPeriod(string $locale): array
    {
        return ['name' => mb_substr($this->name($locale), 0, 120), 'start' => Days::key($this->firstNight()), 'end' => Days::key($this->lastNight()), 'night_type' => 'holiday', 'min_stay' => null, 'floor' => null];
    }

    /**
     * @param list<array{start: string, end: string}> $periods the calendar.holidays of the rules
     */
    public function markListed(array $periods): self
    {
        foreach ($periods as $period) {
            if (Days::isValid((string) ($period['start'] ?? '')) && Days::isValid((string) ($period['end'] ?? ''))
                && Days::date($period['start']) <= $this->lastNight() && $this->firstNight() <= Days::date($period['end'])) {
                return new self($this->holidays, $this->firstDayOff, $this->lastDayOff, $this->onWeekend, true);
            }
        }

        return $this;
    }
}
