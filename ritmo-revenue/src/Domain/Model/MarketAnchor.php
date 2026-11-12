<?php

declare(strict_types=1);

namespace Ritmo\Domain\Model;

use DateTimeImmutable;
use InvalidArgumentException;
use Ritmo\Domain\Support\Days;

/**
 * The market anchor of one night: percentiles of the PUBLIC nightly price of comparable
 * competitors (public plan, property currency).
 */
final class MarketAnchor
{
    public readonly DateTimeImmutable $date;

    public readonly ?DateTimeImmutable $capturedAt;

    /**
     * @param int                    $n          size of the sample behind the percentiles
     * @param DateTimeImmutable|null $capturedAt date-only: when the sample was taken; null when unknown
     *
     * @throws InvalidArgumentException when a value is negative or the percentiles are not in ascending order
     */
    public function __construct(
        DateTimeImmutable $date,
        public readonly float $p25,
        public readonly float $p50,
        public readonly float $p75,
        public readonly float $p90,
        public readonly float $max,
        public readonly int $n,
        ?DateTimeImmutable $capturedAt,
        public readonly AnchorOrigin $origin,
    ) {
        $previous = 0.0;
        foreach ([$p25, $p50, $p75, $p90, $max] as $value) {
            if (!is_finite($value) || $value < $previous) {
                throw new InvalidArgumentException('Anchor percentiles must be non-negative and in ascending order (p25 <= p50 <= p75 <= p90 <= max).');
            }
            $previous = $value;
        }
        if ($n < 0) {
            throw new InvalidArgumentException('The anchor sample size cannot be negative.');
        }
        $this->date = Days::normalize($date);
        $this->capturedAt = $capturedAt === null ? null : Days::normalize($capturedAt);
    }
}
