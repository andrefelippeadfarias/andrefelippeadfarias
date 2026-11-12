<?php

declare(strict_types=1);

namespace Ritmo\Domain\Pricing\Cadence;

use DateTimeImmutable;
use InvalidArgumentException;
use Ritmo\Domain\Model\Identifier;
use Ritmo\Domain\Pricing\StepDirection;
use Ritmo\Domain\Support\Days;

/**
 * The step an item carries: where the price was, where it goes and what was sold. Only an item
 * that is written and carries a direction becomes a ledger row ({@see StepLedger::record()}).
 *
 * Reference: the "pace" block of a plan item (pace.py item_do_plano and _plano).
 */
final class StepProposal
{
    public readonly DateTimeImmutable $night;

    /**
     * @param string             $sourceStatus pace status of the step, or StepRecord::LADDER for a ladder step
     * @param StepDirection|null $direction    null when the change is inside the tolerance (not a step)
     * @param int                $targetSent   target of the engine in the sent plan, rounded
     */
    public function __construct(
        public readonly string $roomCode,
        DateTimeImmutable $night,
        public readonly string $sourceStatus,
        public readonly ?StepDirection $direction,
        public readonly float $priceFrom,
        public readonly float $priceTo,
        public readonly int $targetSent,
        public readonly ?int $sold,
        public readonly SoldBase $soldBase,
        public readonly ?int $soldTotal,
    ) {
        if (!Identifier::isCode($roomCode)) {
            throw new InvalidArgumentException(sprintf('Invalid room type code "%s".', $roomCode));
        }
        $this->night = Days::normalize($night);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'room' => $this->roomCode,
            'night' => Days::key($this->night),
            'source_status' => $this->sourceStatus,
            'direction' => $this->direction?->value,
            'price_from' => $this->priceFrom,
            'price_to' => $this->priceTo,
            'target_sent' => $this->targetSent,
            'sold' => $this->sold,
            'sold_base' => $this->soldBase->value,
            'sold_total' => $this->soldTotal,
        ];
    }
}
