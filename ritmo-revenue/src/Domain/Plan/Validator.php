<?php

declare(strict_types=1);

namespace Ritmo\Domain\Plan;

use Closure;
use DateTimeImmutable;
use InvalidArgumentException;
use Ritmo\Domain\Config\RuleSet;
use Ritmo\Domain\Model\Property;
use Ritmo\Domain\Model\RoomType;
use Ritmo\Domain\Pricing\Floors;
use Ritmo\Domain\Support\Clock;
use Ritmo\Domain\Support\Days;

/**
 * The plan validator of milestone 1: the minimum set of safety rules. It is the only way to get
 * a ValidatedPlan.
 *
 * Errors (any of them stops the whole plan):
 *
 * - the plan was computed for another day; a room that is not of the property; two items for
 *   the same room night; an item without a reason;
 * - a night before today;
 * - a floor for tonight above tonight's price ("the price of today never goes up"), or without
 *   the current price to compare with;
 * - a floor (or, in an item with a ceiling only, the ceiling) under the safety limit, under the
 *   listing minimum or under the hard floor of the night;
 * - a floor or a ceiling over the listing maximum;
 * - a ceiling under the floor of the item, or under the floor in force when the item has none;
 * - an item that changes nothing of what is in force.
 *
 * Floors are compared as the pricing system will apply them: a whole percentage over the base,
 * rounded up (PercentRounding).
 *
 * Reference: the same checks of validar_plano.Validador.validar_data and pace.sem_efeito; the
 * rest of that validator (stay rules, relations, owner orders, exceptions) comes later.
 */
final class Validator
{
    /**
     * A step is rounded to whole currency units, so a floor held at the hard floor may stand up
     * to one unit under it (the reference's margin).
     */
    public const FLOOR_MARGIN = 1.0;

    /** Slack over the listing maximum (the reference's). */
    public const LISTING_MAX_MARGIN = 0.5;

    /** Two amounts within one currency unit are the same price (the reference's). */
    public const SAME_AMOUNT = 1.0;

    private readonly Floors $floors;

    private readonly PercentRounding $rounding;

    /** @var list<PlanIssue> */
    private array $errors = [];

    /** @var list<PlanIssue> */
    private array $warnings = [];

    public function __construct(private readonly RuleSet $rules, private readonly Clock $clock)
    {
        $this->floors = new Floors($rules);
        $this->rounding = new PercentRounding();
    }

    /**
     * @param list<RoomType> $rooms the room types of the property
     *
     * @throws InvalidArgumentException when the plan is of another property
     */
    public function validate(Plan $plan, Property $property, array $rooms): ValidationResult
    {
        if ($plan->propertyId !== $property->id) {
            throw new InvalidArgumentException(sprintf('The plan is for property "%s", not "%s".', $plan->propertyId, $property->id));
        }
        $this->errors = [];
        $this->warnings = [];

        $today = $this->clock->today($property->timezone);
        if (Days::key($plan->today) !== Days::key($today)) {
            $this->errors[] = new PlanIssue(PlanIssue::ERROR, PlanIssue::STALE_PLAN, 'The plan was computed for another day and must be redone.', null, null, null, [
                'plan_today' => Days::key($plan->today),
                'today' => Days::key($today),
            ]);
        }

        $byCode = [];
        foreach ($rooms as $room) {
            if ($room->propertyId === $property->id) {
                $byCode[$room->code] = $room;
            }
        }
        $seen = [];
        foreach ($plan->items as $index => $item) {
            $room = $byCode[$item->roomCode] ?? null;
            if ($room === null) {
                $this->error($index, $item, PlanIssue::UNKNOWN_ROOM, sprintf('Room "%s" is not a room type of this property.', $item->roomCode));
                continue;
            }
            if (isset($seen[$item->key()])) {
                $this->error($index, $item, PlanIssue::DUPLICATE_ITEM, 'There is already an item for this room night; the later one would silently overwrite it.', ['first_item' => $seen[$item->key()]]);
            }
            $seen[$item->key()] ??= $index;
            if ($item->reasonCode === '') {
                $this->error($index, $item, PlanIssue::MISSING_REASON, 'The item has no reason code for the change log.');
            }

            $daysOut = Days::diff($today, $item->date);
            if ($daysOut < 0) {
                $this->error($index, $item, PlanIssue::PAST_DATE, 'The night is in the past.');
                continue;
            }
            $this->checkItem($index, $this->rounding->convert($item, $room), $room, $daysOut);
        }

        return new ValidationResult($this->errors, $this->warnings, $this->errors === [] ? $this->seal($plan, $today, $property->timezone) : null);
    }

    private function checkItem(int $index, PercentItem $percent, RoomType $room, int $daysOut): void
    {
        $this->checkFloors($index, $percent, $room);
        $this->checkListingMaximum($index, $percent, $room);
        $this->checkCeilingAgainstFloor($index, $percent->item);
        if ($daysOut === 0) {
            $this->checkToday($index, $percent);
        }
        $this->checkEffect($index, $percent);
        $this->checkRise($index, $percent, $room, $daysOut);
    }

    /**
     * The lowest price the item allows (its floor; its ceiling when it has no floor, because a
     * ceiling is the highest price the night can have) against the floors of the night.
     */
    private function checkFloors(int $index, PercentItem $percent, RoomType $room): void
    {
        $lowest = $percent->minAmount() ?? $percent->maxAmount();
        if ($lowest === null) {
            return;
        }

        $what = $percent->minPercent !== null ? 'floor' : 'ceiling';
        $safety = $this->floors->safetyLimit($room);
        $hard = $this->floors->hardFloor($room, $percent->item->date);
        if ($lowest < $safety) {
            $this->error($index, $percent->item, PlanIssue::BELOW_SAFETY_LIMIT, sprintf('The %s of %.2f is under the safety limit of %.2f.', $what, $lowest, $safety), [
                'amount' => $lowest,
                'safety_limit' => $safety,
            ]);
        } elseif ($lowest < $hard->amount - self::FLOOR_MARGIN) {
            $code = $hard->label === Floors::LISTING_MIN ? PlanIssue::BELOW_LISTING_MIN : PlanIssue::BELOW_HARD_FLOOR;
            $this->error($index, $percent->item, $code, sprintf('The %s of %.2f is under the hard floor of the night, %.2f (%s).', $what, $lowest, $hard->amount, $hard->label), [
                'amount' => $lowest,
                'hard_floor' => $hard->amount,
                'floor_label' => $hard->label,
            ]);
        }
    }

    private function checkListingMaximum(int $index, PercentItem $percent, RoomType $room): void
    {
        $limit = $room->max + self::LISTING_MAX_MARGIN;
        $floor = $percent->minAmount();
        if ($floor !== null && $floor > $limit) {
            $this->error($index, $percent->item, PlanIssue::ABOVE_LISTING_MAX, sprintf('The floor of %.2f is over the listing maximum of %.2f.', $floor, $room->max), [
                'amount' => $floor,
                'listing_max' => $room->max,
            ]);
        }
        $ceiling = $percent->item->maxPrice;
        if ($ceiling !== null && $ceiling > $limit) {
            $this->error($index, $percent->item, PlanIssue::ABOVE_LISTING_MAX, sprintf('The ceiling of %.2f is over the listing maximum of %.2f.', $ceiling, $room->max), [
                'amount' => $ceiling,
                'listing_max' => $room->max,
            ]);
        }
    }

    /**
     * A ceiling under the floor leaves the pricing system at the floor: the ceiling would not do
     * what the plan says.
     */
    private function checkCeilingAgainstFloor(int $index, PlanItem $item): void
    {
        if ($item->maxPrice === null) {
            return;
        }

        $floor = $item->minPrice;
        $inForce = false;
        if ($floor === null && ($item->before?->minPrice ?? 0.0) > $item->maxPrice + self::SAME_AMOUNT) {
            $floor = $item->before->minPrice;
            $inForce = true;
        }
        if ($floor !== null && $item->maxPrice < $floor) {
            $this->error($index, $item, PlanIssue::CEILING_BELOW_FLOOR, sprintf('The ceiling of %.2f is under the floor %s, %.2f.', $item->maxPrice, $inForce ? 'in force' : 'of the item', $floor), [
                'ceiling' => $item->maxPrice,
                'floor' => $floor,
                'floor_in_force' => $inForce,
            ]);
        }
    }

    /**
     * The price of tonight never goes up: a floor above tonight's price would raise it.
     */
    private function checkToday(int $index, PercentItem $percent): void
    {
        $item = $percent->item;
        if ($item->minPrice === null || !$this->rules->neverRaiseToday()) {
            return;
        }

        $current = $item->before?->price;
        if ($current === null) {
            $this->error($index, $item, PlanIssue::TODAY_WITHOUT_CURRENT_PRICE, 'A floor for tonight needs the current price of the night, to show that it does not raise it.');
        } elseif ($item->minPrice > $current) {
            $this->error($index, $item, PlanIssue::RAISE_TODAY, sprintf('The floor of %.2f would raise the price of tonight (%.2f).', $item->minPrice, $current), [
                'floor' => $item->minPrice,
                'current_price' => $current,
            ]);
        } elseif ($percent->minAmount(2) > $current) {
            $this->warning($index, $item, PlanIssue::TODAY_FLOOR_ROUNDS_ABOVE_PRICE, sprintf('Rounded up to a whole percentage, the floor becomes %.2f, above the price of tonight (%.2f).', $percent->minAmount(2), $current), [
                'applied_floor' => $percent->minAmount(2),
                'current_price' => $current,
            ]);
        }
    }

    private function checkEffect(int $index, PercentItem $percent): void
    {
        $item = $percent->item;
        $before = $item->before;
        if ($before === null) {
            $this->warning($index, $item, PlanIssue::NO_BEFORE, 'Without what is in force for the night, the item cannot be checked for effect.');

            return;
        }

        $sameFloor = $percent->minPercent === null || ($before->minPrice !== null && abs($percent->minAmount(2) - $before->minPrice) <= self::SAME_AMOUNT);
        $sameCeiling = $percent->maxPercent === null || ($before->maxPrice !== null && abs($percent->maxAmount(2) - $before->maxPrice) <= self::SAME_AMOUNT);
        $sameStay = $item->minStay === null || $before->minStay === $item->minStay;
        if ($sameFloor && $sameCeiling && $sameStay) {
            $this->error($index, $item, PlanIssue::NO_EFFECT, 'Everything the item sets is already in force: writing it would change nothing.');
        }
    }

    /**
     * A floor far above the current price moves the night by more than one step of the pace
     * engine: allowed (a floor may require it), but worth a look.
     */
    private function checkRise(int $index, PercentItem $percent, RoomType $room, int $daysOut): void
    {
        $current = $percent->item->before?->price;
        $floor = $percent->minAmount();
        if ($current === null || $current <= 0.0 || $floor === null) {
            return;
        }

        $maxStep = $this->rules->maxStepPercent($daysOut);
        // One whole percentage of the base plus one unit: what the two roundings of a step can add.
        if ($floor > $current * (1 + $maxStep / 100) + 0.01 * $room->base + 1) {
            $this->warning($index, $percent->item, PlanIssue::RISE_ABOVE_MAX_STEP, sprintf('The floor of %.2f is more than %s%% above the current price (%.2f).', $floor, $maxStep, $current), [
                'applied_floor' => $floor,
                'current_price' => $current,
                'max_step_percent' => $maxStep,
            ]);
        }
    }

    /**
     * Creates the ValidatedPlan through its private constructor: this method is the only place
     * that can.
     */
    private function seal(Plan $plan, DateTimeImmutable $today, string $timezone): ValidatedPlan
    {
        $create = Closure::bind(
            static fn (Plan $plan, DateTimeImmutable $validatedAt, DateTimeImmutable $today, string $timezone, string $rulesHash, array $warnings): ValidatedPlan
                => new ValidatedPlan($plan, $validatedAt, $today, $timezone, $rulesHash, $warnings),
            null,
            ValidatedPlan::class
        );

        return $create($plan, $this->clock->now(), $today, $timezone, $this->rules->hash(), $this->warnings);
    }

    /**
     * @param array<string, scalar|null> $params
     */
    private function error(int $index, PlanItem $item, string $code, string $message, array $params = []): void
    {
        $this->errors[] = new PlanIssue(PlanIssue::ERROR, $code, $message, $index, $item->roomCode, $item->date, $params);
    }

    /**
     * @param array<string, scalar|null> $params
     */
    private function warning(int $index, PlanItem $item, string $code, string $message, array $params = []): void
    {
        $this->warnings[] = new PlanIssue(PlanIssue::WARNING, $code, $message, $index, $item->roomCode, $item->date, $params);
    }
}
