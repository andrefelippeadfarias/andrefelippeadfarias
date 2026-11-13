<?php

declare(strict_types=1);

namespace Ritmo\Domain\Operations;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Ritmo\Domain\Support\Days;

/**
 * The slots of one property's schedule in its timezone. Pure: it takes instants and never reads
 * the clock.
 *
 * A local time inside a spring-forward gap falls on the first valid instant after the gap (02:30
 * in a 02:00 to 03:00 gap is 03:00 local); a local time that repeats when the clocks go back
 * falls on its earlier instant, and the repetition is not a second slot.
 *
 * Reference: cota_recalculo.inicio_rodada (latestAtOrBefore) and proxima_rotina_completa
 * (nextFullAfter), with the schedule as configuration instead of constants.
 */
final class SlotPlanner
{
    /** How many local days latestAtOrBefore and nextFullAfter look back or forward. */
    private const SEARCH_DAYS = 2;

    private const SHIFTS = [3600, 1800];

    private readonly DateTimeZone $zone;

    /**
     * @throws InvalidArgumentException when the timezone is not a valid IANA identifier
     */
    public function __construct(private readonly ScheduleConfig $config, private readonly string $timezone)
    {
        try {
            $this->zone = new DateTimeZone($timezone);
        } catch (\Exception $e) {
            throw new InvalidArgumentException(sprintf('Invalid timezone "%s".', $timezone), 0, $e);
        }
    }

    /**
     * The property's local date of an instant (date-only).
     */
    public function localDate(DateTimeImmutable $instant): DateTimeImmutable
    {
        return Days::localDate($instant, $this->timezone);
    }

    /**
     * The local time of an instant, to the minute.
     */
    public function localTime(DateTimeImmutable $instant): LocalTime
    {
        return LocalTime::fromString($instant->setTimezone($this->zone)->format('H:i'));
    }

    /**
     * The UTC instant of a local date and time.
     */
    public function occurrence(DateTimeImmutable $localDate, LocalTime $time): DateTimeImmutable
    {
        $text = Days::key($localDate) . ' ' . $time->toString();
        $instant = new DateTimeImmutable($text . ':00', $this->zone);
        if ($this->local($instant) !== $text) {
            // Inside a spring-forward gap: the clock jumps at the transition, the first valid instant after it.
            $transitions = $this->zone->getTransitions($instant->getTimestamp() - 86400, $instant->getTimestamp());
            $last = $transitions === false || $transitions === [] ? null : $transitions[count($transitions) - 1];
            $instant = $last === null ? $instant : new DateTimeImmutable('@' . $last['ts']);
        } else {
            foreach (self::SHIFTS as $shift) {
                $earlier = new DateTimeImmutable('@' . ($instant->getTimestamp() - $shift));
                if ($this->local($earlier) === $text) {
                    $instant = $earlier;
                    break;
                }
            }
        }

        return $instant->setTimezone(new DateTimeZone('UTC'));
    }

    /**
     * Every configured slot of one local date, ascending.
     *
     * @return list<Slot>
     */
    public function slotsOn(DateTimeImmutable $localDate): array
    {
        return array_map(
            fn (array $entry): Slot => new Slot($entry[0], $localDate, $entry[1], $this->occurrence($localDate, $entry[1])),
            $this->config->times()
        );
    }

    /**
     * The slots whose instant is after $fromExclusive and at or before $toInclusive, ascending.
     *
     * @return list<Slot>
     */
    public function slotsBetween(DateTimeImmutable $fromExclusive, DateTimeImmutable $toInclusive): array
    {
        if ($toInclusive <= $fromExclusive) {
            return [];
        }
        $slots = [];
        $last = $this->localDate($toInclusive);
        for ($date = $this->localDate($fromExclusive); $date <= $last; $date = Days::add($date, 1)) {
            foreach ($this->slotsOn($date) as $slot) {
                if ($slot->at > $fromExclusive && $slot->at <= $toInclusive) {
                    $slots[] = $slot;
                }
            }
        }
        usort($slots, static fn (Slot $a, Slot $b): int => $a->at <=> $b->at);

        return $slots;
    }

    /**
     * The latest slot of any kind at or before $now (inclusive), or null when there is none in the
     * last two local days.
     */
    public function latestAtOrBefore(DateTimeImmutable $now): ?Slot
    {
        $latest = null;
        $today = $this->localDate($now);
        for ($date = Days::add($today, -self::SEARCH_DAYS); $date <= $today; $date = Days::add($date, 1)) {
            foreach ($this->slotsOn($date) as $slot) {
                if ($slot->at <= $now && ($latest === null || $slot->at > $latest->at)) {
                    $latest = $slot;
                }
            }
        }

        return $latest;
    }

    /**
     * The first full slot strictly after $now.
     */
    public function nextFullAfter(DateTimeImmutable $now): Slot
    {
        $date = $this->localDate($now);
        for ($day = 0; $day <= self::SEARCH_DAYS; ++$day) {
            $at = $this->occurrence(Days::add($date, $day), $this->config->fullTime);
            if ($at > $now) {
                return new Slot(SlotKind::Full, Days::add($date, $day), $this->config->fullTime, $at);
            }
        }

        throw new \LogicException('A full slot exists on every local day.');
    }

    /**
     * The first configured slot strictly after $now, of any kind.
     */
    public function nextAfter(DateTimeImmutable $now): Slot
    {
        $date = $this->localDate($now);
        for ($day = 0; $day <= self::SEARCH_DAYS; ++$day) {
            foreach ($this->slotsOn(Days::add($date, $day)) as $slot) {
                if ($slot->at > $now) {
                    return $slot;
                }
            }
        }

        throw new \LogicException('A slot exists on every local day.');
    }

    private function local(DateTimeImmutable $instant): string
    {
        return $instant->setTimezone($this->zone)->format('Y-m-d H:i');
    }
}
