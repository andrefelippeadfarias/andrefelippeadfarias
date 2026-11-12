<?php

declare(strict_types=1);

namespace Ritmo\Domain\Pricing\Ladder;

use DateTimeImmutable;
use OutOfBoundsException;
use Ritmo\Domain\Calendar\HolidayCalendar;
use Ritmo\Domain\Config\FloorRule;
use Ritmo\Domain\Market\PriceFactor;
use Ritmo\Domain\Model\NightType;
use Ritmo\Domain\Model\PriceNight;
use Ritmo\Domain\Model\PricePlan;
use Ritmo\Domain\Model\RoomType;
use Ritmo\Domain\Support\Days;
use Ritmo\Domain\Support\Num;

/**
 * The proportional ladder mode (optional; off in the neutral and parity presets): each booking
 * moves the price along the line from the price in force to a market target, in proportion to
 * the units sold; the last unit sold reaches the target. A cancellation moves it back, never
 * below the owner floor. Without a market target, a fixed step per booking up to a ceiling.
 *
 * It works on two calendar readings (before and after), not on a ruler. The ordering relations
 * that follow its moves belong to the relations pass.
 *
 * Reference: escada_reservas.py (alvo, piso_dono, proporcional, teto, passo, plano).
 */
final class ProportionalLadder
{
    /**
     * @param array<string, RoomType> $rooms       by room code
     * @param list<FloorRule>         $ownerFloors the owner floors of the rule set
     * @param list<int>               $weekendDays Monday = 0
     */
    public function __construct(
        private readonly LadderStrategy $strategy,
        private readonly array $rooms,
        private readonly array $ownerFloors,
        private readonly PriceFactor $factors,
        private readonly HolidayCalendar $calendar,
        private readonly array $weekendDays,
        private readonly MarketTopTable $tops,
    ) {
    }

    /**
     * The price on the line that ends at the target when the last unit sells.
     */
    public static function proportional(float $current, float $target, int $units, int $soldBefore, int $soldAfter): float
    {
        if ($soldBefore >= $units) {
            return $current;
        }

        return $target - ($target - $current) * ($units - min($soldAfter, $units)) / ($units - $soldBefore);
    }

    /**
     * The sent target of the night: the market top over the public factor, never above the listing
     * maximum; the discount room takes a share of its reference room's target. Null without a top.
     */
    public function target(string $room, DateTimeImmutable $night): ?float
    {
        $rule = $this->strategy->proportional;
        if ($room === $rule->bandRoom && $rule->bandReferenceRoom !== null) {
            $reference = $this->target($rule->bandReferenceRoom, $night);

            return $reference === null ? null : Num::roundHalfEven($rule->bandFactor * $reference, 2);
        }
        $top = $this->tops->topFor($night);
        if ($top === null) {
            return null;
        }
        $roomType = $this->room($room);
        $factor = $this->factors->factor($roomType, PricePlan::PublicRate, $this->calendar->nightType($night));

        return Num::roundHalfEven(min($top['top'] / $factor, $roomType->max), 2);
    }

    /**
     * The ceiling of the fallback step: the owner floor of the weekday or weekend in the sent plan
     * (the discount room: a share of its reference room's). Null when no owner floor covers the room.
     */
    public function ceiling(string $room, DateTimeImmutable $night): ?float
    {
        $rule = $this->strategy->proportional;
        if ($room === $rule->bandRoom && $rule->bandReferenceRoom !== null) {
            $reference = $this->ceiling($rule->bandReferenceRoom, $night);

            return $reference === null ? null : Num::roundHalfEven($rule->bandFactor * $reference, 2);
        }
        $floor = $this->ownerFloorRule($room, $night);

        return $floor === null ? null : Num::roundHalfEven($this->toSent($floor['rule']->amount, $floor, $room), 2);
    }

    /**
     * The lowest price a cancellation brings the night to: the owner floor (or the temporary
     * minimum while it lasts) in the sent plan; the discount room a share of its reference room's.
     */
    public function ownerFloor(string $room, DateTimeImmutable $night): ?float
    {
        $rule = $this->strategy->proportional;
        if ($room === $rule->bandRoom && $rule->bandReferenceRoom !== null) {
            $reference = $this->ownerFloor($rule->bandReferenceRoom, $night);

            return $reference === null ? null : Num::roundHalfEven($rule->bandFactor * $reference, 2);
        }
        $floor = $this->ownerFloorRule($room, $night);
        if ($floor === null) {
            return null;
        }
        $temporary = $rule->temporaryFloor !== null && $rule->temporaryFloorUntil !== null && Days::normalize($night) <= $rule->temporaryFloorUntil;

        return Num::roundHalfEven($this->toSent($temporary ? $rule->temporaryFloor : $floor['rule']->amount, $floor, $room), 2);
    }

    /**
     * The fallback step without a market target: (1 + step%)^bookings, rounded up on a sale
     * (never above the ceiling) and down on a cancellation; tonight and past nights never move.
     */
    public function fallbackStep(string $room, DateTimeImmutable $night, float $current, int $bookings, DateTimeImmutable $today): float
    {
        if (Days::normalize($night) <= Days::normalize($today)) {
            return $current;
        }
        $moved = $current * (1 + $this->strategy->proportional->fallbackStepPercent / 100) ** $bookings;
        $new = (float) ($bookings >= 0 ? Num::ceilInt($moved) : Num::floorInt($moved));
        if ($bookings > 0) {
            $ceiling = $this->ceiling($room, $night);
            if ($ceiling !== null) {
                $new = min($new, (float) Num::floorInt($ceiling));
            }
            if ($new <= $current) {
                return $current;
            }
        }

        return $new;
    }

    /**
     * The moves for every room night whose sales changed between two readings.
     *
     * @param array<string, array<string, PriceNight>> $before room code => night => calendar night
     * @param array<string, array<string, PriceNight>> $after  the same, read now
     * @param array<string, int>                       $batch  "ROOM|YYYY-MM-DD" => units sold by batch imports
     *
     * @return list<ProportionalMove>
     */
    public function plan(array $before, array $after, DateTimeImmutable $today, array $batch = []): array
    {
        $band = $this->strategy->proportional->bandRoom;
        $order = array_keys($after);
        usort($order, static fn (string $a, string $b): int => ($a === $band) <=> ($b === $band));
        $moves = [];
        $finals = [];
        foreach ($order as $room) {
            $nights = $after[$room];
            ksort($nights);
            foreach ($nights as $key => $now) {
                $move = $this->move($room, $now, $before[$room][$key] ?? null, $after, $finals, $today, $batch[$room . '|' . $key] ?? 0);
                if ($move !== null) {
                    $moves[] = $move;
                    $finals[$room . '|' . $key] = $move->minPrice;
                }
            }
        }

        return $moves;
    }

    /**
     * @param array<string, array<string, PriceNight>> $after
     * @param array<string, float>                     $finals
     */
    private function move(string $room, PriceNight $now, ?PriceNight $then, array $after, array $finals, DateTimeImmutable $today, int $batch): ?ProportionalMove
    {
        $units = $this->room($room)->units;
        if ($then === null || $now->date <= Days::normalize($today)) {
            return null;
        }
        $soldBefore = $then->sold;
        $soldAfter = $now->sold - $batch;
        $rule = $this->strategy->proportional;
        if ($soldAfter === $soldBefore || $soldBefore >= $units || $now->sold >= $units || ($room === $rule->bandRoom && $soldAfter < $soldBefore)) {
            return null;
        }
        $target = $this->target($room, $now->date);
        $new = $target === null
            ? $this->fallbackStep($room, $now->date, $now->price, $soldAfter - $soldBefore, $today)
            : self::proportional($now->price, $target, $units, $soldBefore, $soldAfter);
        $new = $this->bandCap($room, $now->date, $new, $after, $finals);
        $sale = $soldAfter > $soldBefore;
        $new = (float) ($sale ? Num::ceilInt($new) : Num::floorInt($new));
        if (!$sale) {
            $floor = $this->ownerFloor($room, $now->date);
            $new = max($new, (float) Num::ceilInt(min($floor ?? $now->price, $now->price)));
        }
        if ($sale ? $new <= $now->price : abs($new - $now->price) < 1) {
            return null;
        }
        $fixed = $now->minPrice !== null && $now->minPrice === $now->maxPrice;

        return new ProportionalMove($room, $now->date, $new, $fixed ? $new : null, $now->price, $soldBefore, $soldAfter, $target, $this->strategy->id, $this->strategy->authorityOrderId);
    }

    /**
     * The discount room stays under the cheapest of its cap rooms that still sells (not on holidays).
     *
     * @param array<string, array<string, PriceNight>> $after
     * @param array<string, float>                     $finals
     */
    private function bandCap(string $room, DateTimeImmutable $night, float $new, array $after, array $finals): float
    {
        $rule = $this->strategy->proportional;
        if ($room !== $rule->bandRoom || $this->calendar->holidayOn($night) !== null) {
            return $new;
        }
        $key = Days::key($night);
        $refs = [];
        foreach ($rule->bandCapRooms as $cap) {
            $row = $after[$cap][$key] ?? null;
            if ($row !== null && !$row->isSoldOut()) {
                $refs[] = $finals[$cap . '|' . $key] ?? $row->price;
            }
        }

        return $refs === [] ? $new : min($new, min($refs) * (1 - $rule->bandCapDiscount));
    }

    /**
     * The owner floor of the room for the weekday or weekend of the night (holidays are not looked at).
     *
     * @return array{rule: FloorRule, type: NightType}|null
     */
    private function ownerFloorRule(string $room, DateTimeImmutable $night): ?array
    {
        $type = in_array(Days::weekday($night), $this->weekendDays, true) ? NightType::Weekend : NightType::Weekday;
        foreach ($this->ownerFloors as $rule) {
            if ($rule->appliesTo($type, $this->room($room))) {
                return ['rule' => $rule, 'type' => $type];
            }
        }

        return null;
    }

    /**
     * @param array{rule: FloorRule, type: NightType} $floor
     */
    private function toSent(float $amount, array $floor, string $room): float
    {
        return $this->factors->toSent($amount, $floor['rule']->pricePlan, $this->room($room), $floor['type']);
    }

    private function room(string $code): RoomType
    {
        return $this->rooms[$code] ?? throw new OutOfBoundsException(sprintf('Unknown room type "%s".', $code));
    }
}
