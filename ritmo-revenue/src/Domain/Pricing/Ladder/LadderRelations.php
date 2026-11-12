<?php

declare(strict_types=1);

namespace Ritmo\Domain\Pricing\Ladder;

use Ritmo\Domain\Calendar\HolidayCalendar;
use Ritmo\Domain\Support\Days;

/**
 * The room relations of a ladder night, solved inside the ladder (the general relations pass
 * leaves the nights a ladder prices alone).
 *
 * - between: the room restarts from its own ladder price, then stays between the cheaper of two
 *   other rooms that sell and a position of the way up to the dearer one; never below its hard floor;
 * - premium: a derived room restarts from its source room's price times its multiplier (never below
 *   the quality standard and its hard floor), then stays above the dearest of the listed rooms that
 *   sell by the night's premium rate.
 *
 * Each sets the band ("limits") the live price, floor and ceiling must respect, widened by one
 * rounding step of the pricing system (percent slack of the base plus the money slack). Applying it
 * again (with what really stays on the channel) restarts from the remembered base.
 *
 * Reference: pace.relativos_recanto.
 */
final class LadderRelations
{
    /**
     * @param list<int> $weekendDays weekdays with the weekend premium rate (Monday = 0)
     */
    public function __construct(
        private readonly SalesLadder $ladder,
        private readonly HolidayCalendar $calendar,
        private readonly array $weekendDays,
        private readonly float $moneySlack = 1.0,
        private readonly float $percentSlack = 0.01,
    ) {
    }

    public function apply(LadderNight $night, LadderReferences $refs): LadderNight
    {
        foreach ($this->ladder->strategy->between as $rule) {
            $quote = $night->quote($rule->room);
            if ($quote !== null) {
                $night = $night->with($this->between($night, $quote, $rule, $refs));
            }
        }
        foreach ($this->ladder->strategy->premium as $rule) {
            $quote = $night->quote($rule->room);
            if ($quote !== null) {
                $night = $night->with($this->premium($night, $quote, $rule, $refs));
            }
        }

        return $night;
    }

    private function between(LadderNight $night, LadderRoomQuote $quote, BetweenRule $rule, LadderReferences $refs): LadderRoomQuote
    {
        $base = $quote->baseSent ?? $quote->sent;
        $values = $this->selling($night, [$rule->low, $rule->high], $refs);
        if ($values === []) {
            return $quote->withRelations(max($base, $quote->hardFloor), null, $base, []);
        }
        $low = min($values);
        $high = max($values);
        $top = max($low, $low + $rule->position * ($high - $low));
        $new = min(max($base, $low), $top);
        $reasons = abs($new - $base) > 0.5 ? [LadderRoomQuote::RELATION_BETWEEN] : [];
        $sent = max($new, $quote->hardFloor);
        $slack = $this->slack($quote->roomCode);

        return $quote->withRelations($sent, [min($low, $sent) - $slack, max($top, $sent) + $slack], $base, $reasons);
    }

    private function premium(LadderNight $night, LadderRoomQuote $quote, PremiumRule $rule, LadderReferences $refs): LadderRoomQuote
    {
        $base = $quote->baseSent ?? $quote->sent;
        $sent = $base;
        $ladderRule = $this->ladder->ruleFor($quote->roomCode, $quote->nightType);
        if ($ladderRule?->derive !== null) {
            $source = $refs->valueOf($night, $ladderRule->derive->fromRoom);
            if ($source !== null) {
                $quality = $this->ladder->qualityFloorSent($quote->roomCode, $night->night, $ladderRule) ?? 0.0;
                $sent = max($source * $ladderRule->derive->multiplier, $quality, $quote->hardFloor);
            }
        }
        $reasons = [];
        $others = $this->selling($night, $rule->over, $refs);
        $rate = $this->premiumRate($night, $rule);
        if ($others !== [] && max($others) * (1 + $rate) > $sent) {
            $sent = max($others) * (1 + $rate);
            $reasons[] = LadderRoomQuote::RELATION_PREMIUM_OVER_MAX;
        }

        return $quote->withRelations($sent, [$sent - $this->slack($quote->roomCode), null], $base, $reasons);
    }

    /**
     * @param list<string> $rooms
     *
     * @return list<float>
     */
    private function selling(LadderNight $night, array $rooms, LadderReferences $refs): array
    {
        $values = [];
        foreach ($rooms as $room) {
            $value = $refs->sells($room) ? $refs->valueOf($night, $room) : null;
            if ($value !== null) {
                $values[] = $value;
            }
        }

        return $values;
    }

    private function premiumRate(LadderNight $night, PremiumRule $rule): float
    {
        $weekend = in_array(Days::weekday($night->night), $this->weekendDays, true);

        return $weekend || $this->calendar->holidayOn($night->night) !== null ? $rule->rateWeekendOrHoliday : $rule->rateOther;
    }

    private function slack(string $room): float
    {
        return $this->percentSlack * $this->ladder->room($room)->base + $this->moneySlack;
    }
}
