<?php

declare(strict_types=1);

namespace Ritmo\Domain\Pricing\Ladder;

use DateTimeImmutable;
use Ritmo\Domain\Plan\NightState;
use Ritmo\Domain\Plan\PlanItem;
use Ritmo\Domain\Pricing\Cadence\StepProposal;
use Ritmo\Domain\Support\Days;

/**
 * The floor (and, when the strategy locks it, the ceiling) a ladder writes for a room night, with
 * the strategy and owner order behind it and the step it records once written.
 */
final class LadderItem
{
    public const REASON = 'LADDER_PRICE';

    public readonly DateTimeImmutable $night;

    /**
     * @param list<string> $reasonCodes LADDER_PRICE first, then the relations that moved the price
     */
    public function __construct(
        public readonly string $roomCode,
        DateTimeImmutable $night,
        public readonly float $minPrice,
        public readonly ?float $maxPrice,
        public readonly float $priceBefore,
        public readonly array $reasonCodes,
        public readonly string $strategyId,
        public readonly ?string $authorityOrderId,
        public readonly StepProposal $step,
    ) {
        $this->night = Days::normalize($night);
    }

    /**
     * The item in the plan's format.
     */
    public function toPlanItem(): PlanItem
    {
        return new PlanItem(
            $this->roomCode,
            $this->night,
            $this->minPrice,
            $this->maxPrice,
            null,
            self::REASON,
            [
                'reason_codes' => $this->reasonCodes,
                'authority' => ['strategy' => $this->strategyId, 'order' => $this->authorityOrderId],
                'step' => $this->step->toArray(),
            ],
            new NightState($this->priceBefore),
        );
    }
}
