<?php

declare(strict_types=1);

namespace Ritmo\Domain\Pricing\Ladder;

use DateTimeImmutable;
use Ritmo\Domain\Calendar\HolidayCalendar;
use Ritmo\Domain\Config\FloorRule;
use Ritmo\Domain\Market\PriceFactor;
use Ritmo\Domain\Model\RoomType;
use Ritmo\Domain\Orders\OrderBook;
use Ritmo\Domain\Pricing\Floors;
use Ritmo\Domain\Pricing\HardFloor;
use Ritmo\Domain\Support\Days;
use Ritmo\Domain\Support\Num;

/**
 * While a strategy acts on a room, on its weekend nights outside holiday periods the owner floors
 * give way to the quality standard: the ladder price itself is the floor of those nights, and
 * every other part of the engine (sold-out protection, pace step on a ruler gap) sees the quality
 * standard instead of the weekend owner floor. It applies to the rooms the quality standard
 * covers, whether or not a ladder rule prices them that night.
 *
 * Reference: pace.pisos_enviados (the branch of the owner's plan).
 */
final class WeekendFloorOverride
{
    public function __construct(
        private readonly LadderStrategy $strategy,
        private readonly ?FloorRule $quality,
        private readonly PriceFactor $factors,
        private readonly HolidayCalendar $calendar,
    ) {
    }

    public function applies(RoomType $room, DateTimeImmutable $night, OrderBook $orders): bool
    {
        return $this->strategy->weekendFloorOverride
            && $this->quality !== null
            && $this->quality->amount > 0
            && $room->hasAllTags($this->quality->roomTags)
            && in_array(Days::weekday($night), $this->strategy->weekendFloorWeekdays, true)
            && $this->calendar->holidayOn($night) === null
            && LadderActivation::isActive($this->strategy, $room->code, $night, $orders);
    }

    /**
     * The standard floor of the night with the override applied: the owner floors leave the
     * candidates and the quality standard enters. Unchanged when the override does not apply.
     */
    public function standardFloor(HardFloor $floor, RoomType $room, DateTimeImmutable $night, OrderBook $orders): HardFloor
    {
        if ($this->quality === null || !$this->applies($room, $night, $orders)) {
            return $floor;
        }
        $candidates = array_values(array_filter(
            $floor->candidates,
            static fn (array $c): bool => $c['label'] !== Floors::OWNER && $c['label'] !== Floors::QUALITY,
        ));
        $candidates[] = [
            'label' => Floors::QUALITY,
            'amount' => $this->factors->toSent($this->quality->amount, $this->quality->pricePlan, $room, $this->calendar->nightType($night)),
        ];
        $winner = $candidates[0];
        foreach ($candidates as $candidate) {
            if ($candidate['amount'] > $winner['amount']) {
                $winner = $candidate;
            }
        }

        return new HardFloor(Num::roundHalfEven($winner['amount'], 2), $winner['label'], $candidates);
    }
}
