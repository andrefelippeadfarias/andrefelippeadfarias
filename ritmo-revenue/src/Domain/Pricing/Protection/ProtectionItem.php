<?php

declare(strict_types=1);

namespace Ritmo\Domain\Pricing\Protection;

use DateTimeImmutable;
use Ritmo\Domain\Plan\NightState;
use Ritmo\Domain\Plan\PlanItem;
use Ritmo\Domain\Support\Days;

/**
 * The floor and ceiling the sold-out protection asks for a room night. Not a step: it never goes
 * through the cadence nor into the ledger. A ceiling equal to the listing maximum means "no
 * ceiling": the plan deletes the override first when the live one has a ceiling.
 */
final class ProtectionItem
{
    public const REASON = 'SOLD_OUT_PROTECTION';

    public readonly DateTimeImmutable $night;

    /**
     * @param float $minPrice     floor, sent plan
     * @param float $maxPrice     ceiling, sent plan: the listing maximum (free) or the relation ceiling
     * @param float $marketPublic market value at the protection percentile, public plan
     */
    public function __construct(
        public readonly string $roomCode,
        DateTimeImmutable $night,
        public readonly float $minPrice,
        public readonly float $maxPrice,
        public readonly float $priceBefore,
        public readonly FloorSource $floorSource,
        public readonly bool $ceilingReleased,
        public readonly int $sold,
        public readonly int $units,
        public readonly float $percentile,
        public readonly float $marketPublic,
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
                'reason_codes' => [self::REASON],
                'protection' => true,
                'floor_source' => $this->floorSource->value,
                'ceiling_released' => $this->ceilingReleased,
                'sold' => $this->sold,
                'units' => $this->units,
                'percentile' => $this->percentile,
                'market_public' => $this->marketPublic,
            ],
            new NightState($this->priceBefore),
        );
    }
}
