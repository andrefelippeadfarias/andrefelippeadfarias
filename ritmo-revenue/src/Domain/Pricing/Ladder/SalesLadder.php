<?php

declare(strict_types=1);

namespace Ritmo\Domain\Pricing\Ladder;

use DateTimeInterface;
use InvalidArgumentException;
use OutOfBoundsException;
use Ritmo\Domain\Calendar\HolidayCalendar;
use Ritmo\Domain\Config\FloorRule;
use Ritmo\Domain\Market\PriceFactor;
use Ritmo\Domain\Model\PricePlan;
use Ritmo\Domain\Model\RoomType;
use Ritmo\Domain\Support\Num;

/**
 * The stepped sales ladder of a strategy, in the public plan of the market ruler.
 *
 * - opening: the ruler value of the rule's series at its percentile, times its multiplier, never
 *   above the reference price of the room (when the rule caps it); a derived room takes the
 *   opening of its source room times its multiplier;
 * - ceiling: the ruler value of the ceiling point, never below the opening; without a value, the
 *   opening (no step can apply);
 * - steps: none while the next unit is within the first trigger_fraction of the units, then one
 *   per unit; a sold-out room is priced as its last unit;
 * - price: opening x (1 + step%)^steps, capped at the ceiling, then the last-minute drop.
 *
 * Reference: plano_dono.abertura_publico, teto_publico, degraus_escada, desce, preco_publico, enviado.
 */
final class SalesLadder
{
    /**
     * @param array<string, RoomType> $rooms   by room code
     * @param FloorRule|null          $quality the quality standard (floors.quality_floor); its night types are ignored:
     *                                         a rule decides with apply_quality_floor
     */
    public function __construct(
        public readonly LadderStrategy $strategy,
        private readonly array $rooms,
        private readonly ?FloorRule $quality,
        private readonly PriceFactor $factors,
        private readonly HolidayCalendar $calendar,
    ) {
    }

    public function room(string $code): RoomType
    {
        return $this->rooms[$code] ?? throw new OutOfBoundsException(sprintf('Unknown room type "%s".', $code));
    }

    /**
     * The room codes the ladder knows, in processing order.
     *
     * @return list<string>
     */
    public function roomCodes(): array
    {
        return array_keys($this->rooms);
    }

    public function ruleFor(string $room, string $nightType): ?LadderRule
    {
        return $this->strategy->ruleFor($this->room($room), $nightType);
    }

    public function openingPublic(string $room, RulerNight $ruler): ?float
    {
        $rule = $this->ruleFor($room, $ruler->type);
        if ($rule === null) {
            return null;
        }
        if ($rule->derive !== null) {
            $source = $this->openingPublic($rule->derive->fromRoom, $ruler);

            return $source === null ? null : $source * $rule->derive->multiplier;
        }
        $value = $rule->opening === null ? null : $ruler->valueAt($rule->opening->series, $rule->opening->percentile);
        if ($value === null) {
            return null;
        }
        $value *= $rule->opening->multiplier;
        $reference = $rule->capAtReference ? $ruler->referenceSent($room) : null;
        if ($reference !== null) {
            $value = min($value, $reference * $this->factor($room, $ruler));
        }

        return $value;
    }

    public function ceilingPublic(string $room, RulerNight $ruler): ?float
    {
        $rule = $this->ruleFor($room, $ruler->type);
        if ($rule === null) {
            return null;
        }
        if ($rule->derive !== null) {
            $source = $this->ceilingPublic($rule->derive->fromRoom, $ruler);

            return $source === null ? null : $source * $rule->derive->multiplier;
        }
        $value = $rule->ceiling === null ? null : $ruler->valueAt($rule->ceiling->series, $rule->ceiling->percentile);
        $opening = $this->openingPublic($room, $ruler);
        if ($value === null) {
            return $opening;
        }

        return max($value, $opening ?? 0.0);
    }

    /**
     * Increments carried by the next unit to sell (unit number sold + 1).
     *
     * @throws InvalidArgumentException on negative counts
     */
    public function steps(int $sold, int $units): int
    {
        if ($sold < 0 || $units < 0) {
            throw new InvalidArgumentException('Unit counts cannot be negative.');
        }
        $half = Num::ceilInt($units * $this->strategy->steps->triggerFraction);

        return $sold < $units ? max(0, $sold + 1 - $half) : max(0, $units - $half);
    }

    /**
     * The last-minute drop applies: the rule takes it, it is on, the night is within the window
     * (0 <= D <= window) and less than the share of the units is sold (strictly).
     */
    public function dropApplies(int $daysOut, int $sold, int $units, LadderRule $rule): bool
    {
        $drop = $this->strategy->drop;

        return $rule->applyDrop && $drop->enabled && $daysOut >= 0 && $daysOut <= $drop->windowDays && $units > 0
            && $sold / $units < $drop->occupancyBelow;
    }

    /**
     * The public price of the next unit, or null when the strategy does not price the room on this
     * night type or the ruler lacks the opening point. A derived room given its source room's
     * public price takes that price times its multiplier, without steps or drop of its own.
     */
    public function publicPrice(string $room, RulerNight $ruler, int $sold, int $units, int $daysOut, ?float $referencePublic = null): ?LadderQuote
    {
        $rule = $this->ruleFor($room, $ruler->type);
        if ($rule === null) {
            return null;
        }
        if ($rule->derive !== null && $referencePublic !== null) {
            return new LadderQuote($referencePublic * $rule->derive->multiplier, $this->openingPublic($room, $ruler), null, 0, false, true);
        }
        $opening = $this->openingPublic($room, $ruler);
        $ceiling = $this->ceilingPublic($room, $ruler);
        if ($opening === null) {
            return null;
        }
        $steps = $this->steps($sold, $units);
        $value = min($opening * (1 + $this->strategy->steps->percentPerUnit / 100) ** $steps, max($ceiling ?: $opening, $opening));
        $dropped = $this->dropApplies($daysOut, $sold, $units, $rule);
        if ($dropped) {
            $value *= 1 - $this->strategy->drop->percent / 100;
        }

        return new LadderQuote($value, $opening, $ceiling, $steps, $dropped, $rule->derive !== null);
    }

    /**
     * A public price in the sent plan with the night's measured factor, never below the quality
     * standard when the rule applies it to the room.
     */
    public function toSent(string $room, RulerNight $ruler, float $public, LadderRule $rule): float
    {
        $sent = $public / $this->factor($room, $ruler);
        $quality = $this->qualityFloorSent($room, $ruler->night, $rule);

        return $quality === null ? $sent : max($sent, $quality);
    }

    /**
     * The quality standard of the room in the sent plan when the rule applies it to the room, else null.
     */
    public function qualityFloorSent(string $room, DateTimeInterface $night, LadderRule $rule): ?float
    {
        $quality = $this->quality;
        $roomType = $this->room($room);
        if (!$rule->applyQualityFloor || $quality === null || $quality->amount <= 0 || !$roomType->hasAllTags($quality->roomTags)) {
            return null;
        }

        return $this->factors->toSent($quality->amount, $quality->pricePlan, $roomType, $this->calendar->nightType($night));
    }

    /**
     * The public factor of the room's property measured on the night; when the ruler has none, the
     * configured factor of the property for the night type (the reference would fail).
     */
    public function factor(string $room, RulerNight $ruler): float
    {
        $roomType = $this->room($room);

        return $ruler->publicFactor($roomType->propertyId)
            ?? $this->factors->factor($roomType, PricePlan::PublicRate, $this->calendar->nightType($ruler->night));
    }
}
