<?php

declare(strict_types=1);

namespace Ritmo\Domain\Pricing;

use DateTimeInterface;
use Ritmo\Domain\Calendar\HolidayCalendar;
use Ritmo\Domain\Config\FloorRule;
use Ritmo\Domain\Config\RuleSet;
use Ritmo\Domain\Market\PriceFactor;
use Ritmo\Domain\Model\NightType;
use Ritmo\Domain\Model\RoomType;
use Ritmo\Domain\Support\Num;

/**
 * The hard floor of a room on a night and the listing limits around it.
 *
 * The hard floor is the highest of:
 *
 * - the minimum price of the listing;
 * - the safety limit: a fraction of the room's reference minimum;
 * - the owner floors of the night type, for the rooms they select;
 * - the quality floor, on the night types and rooms it selects;
 * - on a night of a configured period, the holiday floor: the period's own floor or the general
 *   one, whichever is higher.
 *
 * Every floor is declared in a price plan and converted to the sent plan with the measured factor
 * of the room and night type (amount / factor).
 *
 * Reference: pace.pisos_enviados, pace.limite_seguranca, pace.teto_anuncio and
 * validar_plano.piso_absoluto.
 */
final class Floors
{
    public const LISTING_MIN = 'listing_min';
    public const REFERENCE_MIN_FRACTION = 'reference_min_fraction';
    public const QUALITY = 'quality_floor';
    public const HOLIDAY = 'holiday_floor';
    public const OWNER = 'owner_floor';

    /**
     * Which rule names the floor when two give the same amount: the later in this list wins.
     */
    private const PRECEDENCE = [self::REFERENCE_MIN_FRACTION, self::LISTING_MIN, self::QUALITY, self::HOLIDAY, self::OWNER];

    private readonly PriceFactor $factors;

    private readonly HolidayCalendar $calendar;

    public function __construct(private readonly RuleSet $rules, ?PriceFactor $factors = null)
    {
        $this->factors = $factors ?? new PriceFactor($rules);
        $this->calendar = new HolidayCalendar($rules);
    }

    /**
     * The safety limit of a room: the configured fraction of its reference minimum (two decimals).
     */
    public function safetyLimit(RoomType $room): float
    {
        return Num::roundHalfEven($this->rules->referenceMinFraction() * $room->referenceMin, 2);
    }

    /**
     * The highest floor the pricing system applies without passing the maximum of the listing:
     * floors are sent as a whole percentage over the base, so this is the listing maximum
     * rounded down to a whole percentage.
     */
    public function highestFloor(RoomType $room): float
    {
        return Num::percentToAmount((float) Num::ceilingToPercent($room->max, $room->base), $room->base, 2);
    }

    public function hardFloor(RoomType $room, DateTimeInterface $night): HardFloor
    {
        $type = $this->calendar->nightType($night);
        $candidates = [
            ['label' => self::LISTING_MIN, 'amount' => $room->min],
            ['label' => self::REFERENCE_MIN_FRACTION, 'amount' => $this->safetyLimit($room)],
        ];

        $holiday = $this->holidayAmount($night, $type);
        if ($holiday !== null) {
            $candidates[] = ['label' => self::HOLIDAY, 'amount' => $this->toSent($holiday, $this->rules->holidayFloor(), $room, $type)];
        }
        foreach ($this->rules->ownerFloors() as $rule) {
            if ($rule->appliesTo($type, $room)) {
                $candidates[] = ['label' => self::OWNER, 'amount' => $this->toSent($rule->amount, $rule, $room, $type)];
            }
        }
        $quality = $this->rules->qualityFloor();
        if ($quality->appliesTo($type, $room)) {
            $candidates[] = ['label' => self::QUALITY, 'amount' => $this->toSent($quality->amount, $quality, $room, $type)];
        }

        $winner = $candidates[0];
        foreach ($candidates as $candidate) {
            if ($candidate['amount'] > $winner['amount'] || ($candidate['amount'] === $winner['amount'] && self::rank($candidate['label']) > self::rank($winner['label']))) {
                $winner = $candidate;
            }
        }

        return new HardFloor(Num::roundHalfEven($winner['amount'], 2), $winner['label'], $candidates);
    }

    /**
     * The holiday floor of the night in its own price plan, or null when none applies: the
     * period's own floor when it has one, never below the general holiday floor where that
     * applies (nights priced as holiday).
     */
    private function holidayAmount(DateTimeInterface $night, NightType $type): ?float
    {
        $holiday = $this->calendar->holidayOn($night);
        if ($holiday === null) {
            return null;
        }

        $general = $type === NightType::Holiday ? $this->rules->holidayFloor()->amount : 0.0;
        $amount = max($holiday->floor ?? $general, $general);

        return $amount > 0.0 ? $amount : null;
    }

    private function toSent(float $amount, FloorRule $rule, RoomType $room, NightType $type): float
    {
        return $this->factors->toSent($amount, $rule->pricePlan, $room, $type);
    }

    private static function rank(string $label): int
    {
        return (int) array_search($label, self::PRECEDENCE, true);
    }
}
