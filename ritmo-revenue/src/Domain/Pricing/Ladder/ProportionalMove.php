<?php

declare(strict_types=1);

namespace Ritmo\Domain\Pricing\Ladder;

use DateTimeImmutable;
use Ritmo\Domain\Plan\NightState;
use Ritmo\Domain\Plan\PlanItem;
use Ritmo\Domain\Support\Days;

/**
 * A price move of the proportional ladder after new sales (or cancellations) of a room night.
 */
final class ProportionalMove
{
    public const REASON = 'PROPORTIONAL_LADDER';
    public const REASON_FALLBACK = 'PROPORTIONAL_LADDER_FALLBACK';

    public readonly DateTimeImmutable $night;

    /**
     * @param float|null $maxPrice the same as the floor when the night had floor = ceiling, else null
     * @param float|null $target   the market target the line ends at; null when the fallback step moved it
     */
    public function __construct(
        public readonly string $roomCode,
        DateTimeImmutable $night,
        public readonly float $minPrice,
        public readonly ?float $maxPrice,
        public readonly float $priceBefore,
        public readonly int $soldBefore,
        public readonly int $soldAfter,
        public readonly ?float $target,
        public readonly string $strategyId,
        public readonly ?string $authorityOrderId,
    ) {
        $this->night = Days::normalize($night);
    }

    public function reason(): string
    {
        return $this->target === null ? self::REASON_FALLBACK : self::REASON;
    }

    public function toPlanItem(): PlanItem
    {
        return new PlanItem(
            $this->roomCode,
            $this->night,
            $this->minPrice,
            $this->maxPrice,
            null,
            $this->reason(),
            [
                'reason_codes' => [$this->reason()],
                'authority' => ['strategy' => $this->strategyId, 'order' => $this->authorityOrderId],
                'sold_before' => $this->soldBefore,
                'sold_after' => $this->soldAfter,
                'target' => $this->target,
            ],
            new NightState($this->priceBefore),
        );
    }
}
