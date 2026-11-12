<?php

declare(strict_types=1);

namespace Ritmo\Domain\Plan;

use InvalidArgumentException;
use Ritmo\Domain\Model\DateOverride;
use Ritmo\Domain\Model\PriceNight;

/**
 * What is in force for a room night before a plan item changes it. Kept with the item so the
 * validator can tell what the item really changes and the change log can say how to undo it.
 *
 * Every value is optional: null means "not known", never "none".
 */
final class NightState
{
    /**
     * @param float|null $price      price of the night (sent plan)
     * @param float|null $minPrice   floor in force (sent plan)
     * @param float|null $maxPrice   ceiling in force (sent plan)
     * @param int|null   $minStay    minimum stay in force
     * @param float|null $minPercent floor of the date override in force, as a percentage over the listing base
     * @param float|null $maxPercent ceiling of the date override in force, as a percentage over the listing base
     *
     * @throws InvalidArgumentException on a negative amount or a stay below one night
     */
    public function __construct(
        public readonly ?float $price = null,
        public readonly ?float $minPrice = null,
        public readonly ?float $maxPrice = null,
        public readonly ?int $minStay = null,
        public readonly ?float $minPercent = null,
        public readonly ?float $maxPercent = null,
    ) {
        foreach ([$price, $minPrice, $maxPrice] as $amount) {
            if ($amount !== null && (!is_finite($amount) || $amount < 0)) {
                throw new InvalidArgumentException('Prices cannot be negative.');
            }
        }
        foreach ([$minPercent, $maxPercent] as $percent) {
            if ($percent !== null && !is_finite($percent)) {
                throw new InvalidArgumentException('Percentages must be finite numbers.');
            }
        }
        if ($minStay !== null && $minStay < 1) {
            throw new InvalidArgumentException('The minimum stay must be at least one night.');
        }
    }

    /**
     * The state of a night from what the pricing system reports: its calendar night and the date
     * override in force, either of which may be missing.
     */
    public static function from(?PriceNight $price, ?DateOverride $override = null): self
    {
        return new self(
            $price?->price,
            $price?->minPrice,
            $price?->maxPrice,
            $override?->minStay ?? $price?->minStay,
            $override?->minPercent,
            $override?->maxPercent,
        );
    }

    /**
     * @return array<string, float|int|null>
     */
    public function toArray(): array
    {
        return [
            'price' => $this->price,
            'min_price' => $this->minPrice,
            'max_price' => $this->maxPrice,
            'min_stay' => $this->minStay,
            'min_percent' => $this->minPercent,
            'max_percent' => $this->maxPercent,
        ];
    }
}
