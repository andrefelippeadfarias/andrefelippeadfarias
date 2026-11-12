<?php

declare(strict_types=1);

namespace Ritmo\Domain\Pricing\Ladder;

use DateTimeInterface;
use Ritmo\Domain\Calendar\HolidayCalendar;
use Ritmo\Domain\Config\FloorRule;
use Ritmo\Domain\Market\PriceFactor;
use Ritmo\Domain\Model\NightType;
use Ritmo\Domain\Model\RoomType;
use Ritmo\Domain\Pricing\Floors;

/**
 * The floors a ladder price never goes below: the safety limit and, on a night of a configured
 * holiday period, the general holiday floor in the sent plan. The listing minimum and the higher
 * floor of the period itself do not apply on ladder nights (the strategy releases them).
 *
 * Reference: pace.piso_duro_do_plano.
 */
final class LadderHardFloor
{
    public const SAFETY_LIMIT = 'safety_limit';
    public const HOLIDAY_SAFETY_FLOOR = 'holiday_safety_floor';

    public function __construct(
        private readonly Floors $floors,
        private readonly HolidayCalendar $calendar,
        private readonly PriceFactor $factors,
        private readonly FloorRule $holidayFloor,
    ) {
    }

    /**
     * @return array{amount: float, label: string}
     */
    public function for(RoomType $room, DateTimeInterface $night): array
    {
        $floor = ['amount' => $this->floors->safetyLimit($room), 'label' => self::SAFETY_LIMIT];
        if ($this->calendar->holidayOn($night) !== null && $this->holidayFloor->amount > 0) {
            $holiday = $this->factors->toSent($this->holidayFloor->amount, $this->holidayFloor->pricePlan, $room, NightType::Holiday);
            if ($holiday > $floor['amount']) {
                $floor = ['amount' => $holiday, 'label' => self::HOLIDAY_SAFETY_FLOOR];
            }
        }

        return $floor;
    }

    /**
     * The safety limit alone (the floor under every last-minute market floor).
     */
    public function safetyLimit(RoomType $room): float
    {
        return $this->floors->safetyLimit($room);
    }
}
