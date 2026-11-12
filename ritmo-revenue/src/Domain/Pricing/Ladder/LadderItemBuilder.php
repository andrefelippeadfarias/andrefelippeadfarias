<?php

declare(strict_types=1);

namespace Ritmo\Domain\Pricing\Ladder;

use DateTimeImmutable;
use Ritmo\Domain\Model\DateOverride;
use Ritmo\Domain\Model\PriceNight;
use Ritmo\Domain\Model\RoomType;
use Ritmo\Domain\Pricing\Cadence\CadenceConfig;
use Ritmo\Domain\Pricing\Cadence\SoldBase;
use Ritmo\Domain\Pricing\Cadence\StepCadence;
use Ritmo\Domain\Pricing\Cadence\StepLedger;
use Ritmo\Domain\Pricing\Cadence\StepProposal;
use Ritmo\Domain\Pricing\Cadence\StepRecord;
use Ritmo\Domain\Pricing\Protection\LiveBounds;
use Ritmo\Domain\Pricing\StepDirection;
use Ritmo\Domain\Support\Num;

/**
 * Turns the ladder price of a room night into the item the plan writes (floor, and ceiling when
 * the strategy locks it) or into a line only.
 *
 * - The price never passes the listing top (the chained maximum the caller gives) and tonight's
 *   price never rises.
 * - Direction: going up the effective value (floor rounded up to a whole percent of the base) is
 *   compared, going down the raw value, with the tolerance. The floor is rounded up going up, down
 *   going down, half to even without a direction, never above the listing top when capped and
 *   never below the hard floor.
 * - Without a direction no item is needed when the live floor and ceiling are already at the
 *   price (within the tolerance or one rounding step) and the room respects its relation limits;
 *   otherwise an item without direction is written (it is not a step).
 * - A room outside the night filter of a light run waits.
 * - A step goes through the cadence, with three exceptions: a booking since the last step, the
 *   first ladder step of the night, a room outside its relation limits.
 *
 * Reference: pace.item_do_plano.
 */
final class LadderItemBuilder
{
    public function __construct(
        private readonly LadderStrategy $strategy,
        private readonly StepCadence $cadence,
        private readonly CadenceConfig $cadenceConfig,
        private readonly float $tolerance,
        private readonly float $moneySlack = 1.0,
        private readonly float $percentSlack = 0.01,
    ) {
    }

    /**
     * @param PriceNight $live       the calendar night, its price already brought within the live override
     * @param float      $listingTop the highest effective floor the room may take (chained listing maximum)
     */
    public function build(
        RoomType $room,
        LadderRoomQuote $quote,
        int $daysOut,
        PriceNight $live,
        ?DateOverride $override,
        StepLedger $ledger,
        DateTimeImmutable $now,
        bool $inFilter,
        float $listingTop,
    ): LadderOutcome {
        $price = $live->price;
        $target = $quote->sent;
        $capped = $target >= $listingTop - 0.01;
        $new = min($target, $listingTop);
        if ($daysOut === 0 && $new > $price) {
            $new = $price;
        }
        $direction = $this->direction($new > $price ? EffectiveValue::floor($new, $room) : $new, $new, $price);
        $rounded = $this->rounded($new, $direction, $capped, $quote->hardFloor);
        $line = new LadderLine(
            $room->code,
            $live->date,
            $daysOut,
            $quote->nightType,
            $quote->sold,
            $quote->total,
            $quote->total > 0 ? Num::roundInt(100 * $quote->sold / $quote->total) : 0,
            Num::roundInt($quote->public),
            Num::roundInt($target),
            $price,
            Num::roundInt($price * $quote->factor),
            $rounded,
            $direction,
        );

        $floor = LiveBounds::floor($live, $override, $room->base);
        $ceiling = LiveBounds::ceiling($live, $override, $room->base);
        $outOfOrder = $quote->limits !== null && !($this->inside($price, $quote) && $this->inside($floor, $quote) && $this->inside($ceiling, $quote));
        if ($direction === null) {
            if ($daysOut === 0 && $target > $price) {
                return new LadderOutcome(null, $line->withHold(LadderLine::TODAY_NEVER_UP));
            }
            if ($this->near($floor, $rounded, $room) && $this->near($ceiling, $rounded, $room) && !$outOfOrder) {
                return new LadderOutcome(null, $line);
            }
        }
        if (!$inFilter) {
            return new LadderOutcome(null, $line->withHold(LadderLine::OUT_OF_NIGHT_FILTER));
        }
        if ($direction !== null) {
            $verdict = $this->cadence->permits($ledger, $room->code, $live->date, $direction, $now, $quote->sold, SoldBase::Room, $quote->total);
            $allowed = $verdict->allowed
                || ($this->cadenceConfig->firstLadderStepImmediate && !$ledger->hasLadderStep($room->code, $live->date))
                || ($this->cadenceConfig->relationViolationBypass && $outOfOrder);
            if (!$allowed) {
                return new LadderOutcome(null, $line->withHold((string) $verdict->code));
            }
        }

        return new LadderOutcome($this->item($room, $quote, $live, $rounded, $direction, $target), $line);
    }

    private function direction(float $compared, float $new, float $price): ?StepDirection
    {
        if ($compared > $price * (1 + $this->tolerance)) {
            return StepDirection::Up;
        }

        return $new < $price * (1 - $this->tolerance) ? StepDirection::Down : null;
    }

    private function rounded(float $new, ?StepDirection $direction, bool $capped, float $hardFloor): int
    {
        $rounded = match ($direction) {
            StepDirection::Up => Num::ceilInt($new),
            StepDirection::Down => Num::floorInt($new),
            null => Num::roundInt($new),
        };
        if ($capped && $rounded > $new) {
            $rounded = Num::floorInt($new);
        }

        return max($rounded, Num::ceilEps($hardFloor));
    }

    private function inside(?float $value, LadderRoomQuote $quote): bool
    {
        if ($value === null || $quote->limits === null) {
            return true;
        }
        [$low, $high] = $quote->limits;

        return ($low === null || $value >= $low) && ($high === null || $value <= $high);
    }

    private function near(?float $value, int $rounded, RoomType $room): bool
    {
        return $value !== null && abs($value - $rounded) <= max($this->tolerance * $rounded, $this->percentSlack * $room->base + $this->moneySlack);
    }

    private function item(RoomType $room, LadderRoomQuote $quote, PriceNight $live, int $rounded, ?StepDirection $direction, float $target): LadderItem
    {
        return new LadderItem(
            $room->code,
            $live->date,
            (float) $rounded,
            $this->strategy->lockCeiling ? (float) $rounded : null,
            $live->price,
            [LadderItem::REASON, ...$quote->relationReasons],
            $this->strategy->id,
            $this->strategy->authorityOrderId,
            new StepProposal(
                $room->code,
                $live->date,
                StepRecord::LADDER,
                $direction,
                $live->price,
                (float) $rounded,
                Num::roundInt($target),
                $quote->sold,
                SoldBase::Room,
                $quote->total,
            ),
        );
    }
}
