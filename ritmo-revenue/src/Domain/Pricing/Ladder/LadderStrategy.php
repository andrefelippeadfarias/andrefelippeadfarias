<?php

declare(strict_types=1);

namespace Ritmo\Domain\Pricing\Ladder;

use DateTimeImmutable;
use DateTimeInterface;
use Ritmo\Domain\Model\RoomType;
use Ritmo\Domain\Support\Days;

/**
 * A sales ladder strategy: a period, the rules that price some rooms on some night types, its
 * steps and drop, the relations solved inside its nights, its last-minute market floor and,
 * optionally, the owner order that must stay in force for it to act.
 *
 * Reference: plano_dono.py (the owner's plan of 9 Nov, made generic).
 */
final class LadderStrategy
{
    public const MODE_STEPPED = 'stepped';
    public const MODE_PROPORTIONAL = 'proportional';

    /**
     * @param list<LadderRule>   $rules     first match wins
     * @param list<int>          $weekendFloorWeekdays nights (by check-in weekday) where the weekend owner floor gives
     *                                                 way to the quality standard while the strategy acts
     * @param list<BetweenRule>  $between
     * @param list<PremiumRule>  $premium
     */
    public function __construct(
        public readonly string $id,
        public readonly string $label = '',
        public readonly bool $enabled = false,
        public readonly string $mode = self::MODE_STEPPED,
        public readonly ?DateTimeImmutable $validFrom = null,
        public readonly ?DateTimeImmutable $validTo = null,
        public readonly ?DateTimeImmutable $activatedOn = null,
        public readonly ?string $authorityOrderId = null,
        public readonly bool $lockCeiling = true,
        public readonly LadderSteps $steps = new LadderSteps(),
        public readonly DropRule $drop = new DropRule(),
        public readonly bool $weekendFloorOverride = false,
        public readonly array $weekendFloorWeekdays = [4, 5],
        public readonly array $rules = [],
        public readonly array $between = [],
        public readonly array $premium = [],
        public readonly LastMinuteFloorRule $lastMinuteFloor = new LastMinuteFloorRule(),
        public readonly ProportionalRule $proportional = new ProportionalRule(),
    ) {
    }

    /**
     * The night is inside the strategy's period (open ends when not set).
     */
    public function covers(DateTimeInterface $night): bool
    {
        $day = Days::normalize($night);

        return ($this->validFrom === null || $this->validFrom <= $day) && ($this->validTo === null || $day <= $this->validTo);
    }

    /**
     * The first rule that prices the room on the night type, or null.
     */
    public function ruleFor(RoomType $room, string $nightType): ?LadderRule
    {
        foreach ($this->rules as $rule) {
            if ($rule->matches($room, $nightType)) {
                return $rule;
            }
        }

        return null;
    }
}
