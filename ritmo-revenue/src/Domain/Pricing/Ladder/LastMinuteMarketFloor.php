<?php

declare(strict_types=1);

namespace Ritmo\Domain\Pricing\Ladder;

use DateTimeImmutable;
use Ritmo\Domain\Calendar\HolidayCalendar;
use Ritmo\Domain\Orders\OrderBook;
use Ritmo\Domain\Support\Days;

/**
 * The last-minute market floor of a strategy, for the nights its ladder does not price: close to
 * the night, the floor of the covered rooms comes down to a market value of the ruler (times the
 * room's multiplier, over the night's public factor), never below the safety limit.
 *
 * The pace step uses it only when it is lower than the engine floor; it may go below the listing
 * minimum but never below the safety limit.
 *
 * Reference: plano_dono.piso_semana_ultima and pace.piso_semana_mercado.
 */
final class LastMinuteMarketFloor
{
    public function __construct(
        private readonly SalesLadder $ladder,
        private readonly MarketRuler $ruler,
        private readonly LadderHardFloor $hardFloors,
        private readonly HolidayCalendar $calendar,
    ) {
    }

    /**
     * The floor (sent plan) and the authority behind it, or null when the rule does not apply.
     *
     * @return array{amount: float, strategy: string, order: ?string}|null
     */
    public function floorFor(string $room, DateTimeImmutable $night, int $daysOut, OrderBook $orders): ?array
    {
        $strategy = $this->ladder->strategy;
        $rule = $strategy->lastMinuteFloor;
        if (!$rule->enabled || $rule->series === null || !LadderActivation::isActive($strategy, $room, $night, $orders)) {
            return null;
        }
        if (($rule->skipHolidays && $this->calendar->holidayOn($night) !== null) || in_array(Days::weekday($night), $rule->excludedWeekdays, true)) {
            return null;
        }
        $ruler = $this->ruler->night($night);
        $multiplier = $rule->rooms[$room] ?? null;
        if ($ruler === null || !in_array($ruler->type, $rule->nightTypes, true) || $multiplier === null || $daysOut < 0 || $daysOut > $rule->windowDays) {
            return null;
        }
        $value = $ruler->valueAt($rule->series, $rule->percentile);
        if ($value === null) {
            return null;
        }
        $amount = max($value * $multiplier / $this->ladder->factor($room, $ruler), $this->hardFloors->safetyLimit($this->ladder->room($room)));

        return ['amount' => $amount, 'strategy' => $strategy->id, 'order' => $strategy->authorityOrderId];
    }
}
