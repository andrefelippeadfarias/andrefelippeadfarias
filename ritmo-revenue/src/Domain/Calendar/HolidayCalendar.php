<?php

declare(strict_types=1);

namespace Ritmo\Domain\Calendar;

use DateTimeInterface;
use Ritmo\Domain\Config\Holiday;
use Ritmo\Domain\Config\RuleSet;
use Ritmo\Domain\Model\NightType;
use Ritmo\Domain\Support\DateRange;

/**
 * The calendar of a rule set: which nights fall in a configured period ("calendar.holidays")
 * and how each night is priced.
 *
 * A night inside a period takes the night type the period declares, which may be any of the
 * three; outside every period it is a weekend or a weekday night by the configured weekend days.
 * When periods overlap, the first one configured wins.
 *
 * Reference: comum.periodo_feriado and ancora_mercado.tipo_noite.
 */
final class HolidayCalendar
{
    public function __construct(private readonly RuleSet $rules)
    {
    }

    /**
     * The configured period that covers the night, or null.
     */
    public function holidayOn(DateTimeInterface $night): ?Holiday
    {
        return $this->rules->holidayOn($night);
    }

    public function isInPeriod(DateTimeInterface $night): bool
    {
        return $this->holidayOn($night) !== null;
    }

    public function nightType(DateTimeInterface $night): NightType
    {
        return $this->rules->nightType($night);
    }

    /**
     * The night type of every night of a range.
     *
     * @return array<string, NightType> 'YYYY-MM-DD' => type, ascending
     */
    public function classify(DateRange $range): array
    {
        $types = [];
        foreach ($range as $key => $night) {
            $types[$key] = $this->nightType($night);
        }

        return $types;
    }

    /**
     * The configured periods with at least one night in the range, in configured order.
     *
     * @return list<Holiday>
     */
    public function periodsIn(DateRange $range): array
    {
        return array_values(array_filter(
            $this->rules->holidays(),
            static fn (Holiday $holiday): bool => $holiday->nights->intersect($range) !== null
        ));
    }
}
