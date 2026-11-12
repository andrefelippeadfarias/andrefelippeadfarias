<?php

declare(strict_types=1);

namespace Ritmo\Domain\Pricing\Terms;

use InvalidArgumentException;

/**
 * The bounds a premium relation puts on its room before the coherence stage: above the dearest
 * room of its set that still sells, and under the high end of its price band when it has one.
 * Reference: pace.regra_afrodite (Relations\PremiumBounds computes the amounts).
 */
final class PremiumCap
{
    /**
     * @param float       $floor    sent plan
     * @param float|null  $ceiling  sent plan; null without a band
     * @param string|null $orderRef the order the relation cites on the items it decides
     */
    public function __construct(
        public readonly float $floor,
        public readonly ?float $ceiling,
        public readonly ?string $orderRef,
    ) {
        if ($floor < 0.0 || ($ceiling !== null && $ceiling < 0.0)) {
            throw new InvalidArgumentException('Premium bounds cannot be negative.');
        }
    }
}
