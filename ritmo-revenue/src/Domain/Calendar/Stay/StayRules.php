<?php

declare(strict_types=1);

namespace Ritmo\Domain\Calendar\Stay;

use DateTimeImmutable;
use InvalidArgumentException;
use Ritmo\Domain\Calendar\HolidayCalendar;
use Ritmo\Domain\Config\Holiday;
use Ritmo\Domain\Model\RoomType;
use Ritmo\Domain\Support\Days;

/**
 * The stay rules of one night of a room: the stay it requires, the stay it must have from the one
 * it has, and where an orphan stay fits.
 *
 * Precedence (highest first): a package night's own stay; a holiday period's own stay (else its
 * season's, else the holiday default); the arrival weekday, raised to the closed day's own stay
 * and to the season outside holidays. The arrival rule decides (raises to its target, lowers
 * only to one night) the nights outside packages, holidays, closed days with their own stay and
 * held weekdays; in a holiday period only its orphan night is decided.
 *
 * Reference: validar_plano.estadia_exigida, regra_do_dia_decide, noite_orfa_decide,
 * orfa_seguinte, estadia_alvo, estadia_planejada; comum.orfa_permitida, sexta_reaberta,
 * data_de_pacote, estadia_pacote; calendario_12m.sabado_com_sexta_passada.
 */
final class StayRules
{
    /** @var array<string, RoomType> */
    private array $rooms = [];

    /**
     * @param list<RoomType> $rooms the rooms the rules apply to
     */
    public function __construct(
        private readonly StayConfig $config,
        private readonly HolidayCalendar $calendar,
        array $rooms,
    ) {
        foreach ($rooms as $room) {
            $this->rooms[$room->code] = $room;
        }
    }

    public function config(): StayConfig
    {
        return $this->config;
    }

    /**
     * @throws InvalidArgumentException for a room the rules do not know
     */
    public function room(string $roomCode): RoomType
    {
        return $this->rooms[$roomCode] ?? throw new InvalidArgumentException(sprintf('Unknown room "%s".', $roomCode));
    }

    public function holiday(DateTimeImmutable $night): ?Holiday
    {
        return $this->calendar->holidayOn($night);
    }

    /**
     * The minimum stay the night requires, or null when nothing requires one.
     */
    public function requiredStay(string $roomCode, DateTimeImmutable $night): ?int
    {
        $package = $this->packageNight($roomCode, $night);
        if ($package?->minStay !== null) {
            return $package->minStay;
        }
        $holiday = $this->holiday($night);
        if ($holiday !== null) {
            return ($holiday->minStay ?? 0) > 0 ? $holiday->minStay : ($this->seasonStay($night) ?? $this->config->holidayMinStay);
        }
        $base = $this->arrivalWeekdayStay($roomCode, $night);
        $closed = $this->closedDay($roomCode, $night)?->minStay;
        if ($base === null) {
            return $closed;
        }

        return max($closed === null ? $base : max($base, $closed), $this->seasonOutsideHolidays($night)?->minStay ?? 1);
    }

    /**
     * The stay the night must have from the current one (null current: no stay set).
     */
    public function plannedStay(string $roomCode, DateTimeImmutable $night, ?int $current, bool $free, bool $nextSoldOut): PlannedStay
    {
        if (!$this->arrivalDecides($roomCode, $night)) {
            if ($this->holiday($night) !== null && $this->orphanDecides($roomCode, $night)) {
                $target = $this->target($roomCode, $night, $free, $nextSoldOut);
                if ($target->orphan) {
                    return $target;
                }
            }

            return PlannedStay::undecided();
        }
        $target = $this->target($roomCode, $night, $free, $nextSoldOut);
        if ($target->minStay === null) {
            return $target;
        }
        if ($current === null || $current < $target->minStay || ($target->minStay === 1 && $current > 1)) {
            return $target;
        }

        return new PlannedStay($current, $target->orphan);
    }

    /**
     * The arrival rule decides the night's stay.
     */
    public function arrivalDecides(string $roomCode, DateTimeImmutable $night): bool
    {
        return !$this->config->arrival->isHeld($roomCode, $night) && $this->arrivalRuleCovers($roomCode, $night);
    }

    /**
     * The night is held (left as it is) where the arrival rule would decide it.
     */
    public function isHeld(string $roomCode, DateTimeImmutable $night): bool
    {
        return $this->config->arrival->isHeld($roomCode, $night) && $this->arrivalRuleCovers($roomCode, $night);
    }

    /**
     * The orphan night of the arrival rule may apply to the night.
     */
    public function orphanDecides(string $roomCode, DateTimeImmutable $night): bool
    {
        return $this->config->arrival->inForce($night)
            && $this->config->orphan->nextNight
            && !$this->closedDayOwnsStay($roomCode, $night)
            && !$this->config->arrival->isHeld($roomCode, $night)
            && $this->orphanAllowed($roomCode, $night, false);
    }

    /**
     * An orphan stay fits the night as to packages, open dates of its closed day and holidays.
     * $closedDaySide is the free closed day of an orphan side; otherwise the orphan night.
     */
    public function orphanAllowed(string $roomCode, DateTimeImmutable $night, bool $closedDaySide): bool
    {
        if ($this->isPackageNight($roomCode, $night)) {
            return false;
        }
        $closed = $this->closedDay($roomCode, $night);
        if ($closedDaySide && $closed?->openDate($night) !== null) {
            return false;
        }
        if ($this->holiday($night) === null) {
            return true;
        }

        return $closedDaySide ? ($closed?->orphanSide->onHolidays ?? false) : $this->config->orphan->onHolidays;
    }

    public function packageNight(string $roomCode, DateTimeImmutable $night): ?PackageNight
    {
        foreach ($this->config->packages as $package) {
            $found = $package->night($roomCode, $night);
            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }

    public function isPackageNight(string $roomCode, DateTimeImmutable $night): bool
    {
        return $this->packageNight($roomCode, $night) !== null;
    }

    /**
     * The first closed-day rule that applies to the room on the night, or null.
     */
    public function closedDay(string $roomCode, DateTimeImmutable $night): ?ClosedDayRule
    {
        foreach ($this->config->closedDays as $rule) {
            if ($rule->appliesTo($roomCode, $night)) {
                return $rule;
            }
        }

        return null;
    }

    /**
     * The weekday is enforced open for the room: not on a package night, nor where a closed-day
     * rule applies.
     */
    public function openDayApplies(string $roomCode, DateTimeImmutable $night): bool
    {
        if ($this->isPackageNight($roomCode, $night) || $this->closedDay($roomCode, $night) !== null) {
            return false;
        }
        foreach ($this->config->openDays as $rule) {
            if ($rule->appliesTo($roomCode, $night)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Today's closed day whose previous night has passed: that night no longer sells and counts
     * as sold out for the orphan side.
     */
    public function closedDayWithPreviousPast(string $roomCode, DateTimeImmutable $night, ?DateTimeImmutable $today): bool
    {
        if ($today === null || $this->closedDay($roomCode, $night) === null) {
            return false;
        }

        return Days::diff($today, Days::add($night, -1)) < 0 && Days::diff($today, $night) >= 0;
    }

    /**
     * The minimum stay of the arrival weekday for the room, or null while the rule is not in force.
     */
    public function arrivalWeekdayStay(string $roomCode, DateTimeImmutable $night): ?int
    {
        return $this->config->arrival->stayFor($roomCode, $this->room($roomCode)->propertyId, $night);
    }

    /**
     * The first season covering the night, when it sets a stay and does not overlap a holiday period.
     */
    public function seasonOutsideHolidays(DateTimeImmutable $night): ?Season
    {
        $season = $this->season($night);
        if ($season === null || $season->minStay === null || $this->calendar->periodsIn($season->nights) !== []) {
            return null;
        }

        return $season;
    }

    private function season(DateTimeImmutable $night): ?Season
    {
        foreach ($this->config->seasons as $season) {
            if ($season->covers($night)) {
                return $season;
            }
        }

        return null;
    }

    private function seasonStay(DateTimeImmutable $night): ?int
    {
        return $this->season($night)?->minStay;
    }

    private function arrivalRuleCovers(string $roomCode, DateTimeImmutable $night): bool
    {
        return $this->config->arrival->inForce($night)
            && $this->holiday($night) === null
            && !$this->isPackageNight($roomCode, $night)
            && !$this->closedDayOwnsStay($roomCode, $night);
    }

    private function closedDayOwnsStay(string $roomCode, DateTimeImmutable $night): bool
    {
        return $this->closedDay($roomCode, $night)?->minStay !== null;
    }

    private function target(string $roomCode, DateTimeImmutable $night, bool $free, bool $nextSoldOut): PlannedStay
    {
        $required = $this->requiredStay($roomCode, $night);
        if ($required !== null && $required > 1 && $free && $nextSoldOut && $this->orphanDecides($roomCode, $night)) {
            return new PlannedStay(1, true);
        }

        return new PlannedStay($required);
    }
}
