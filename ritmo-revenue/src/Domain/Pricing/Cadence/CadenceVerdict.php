<?php

declare(strict_types=1);

namespace Ritmo\Domain\Pricing\Cadence;

/**
 * Whether a step may go now, and the reason when it must wait.
 */
final class CadenceVerdict
{
    /** The previous step in the same direction has not reached the sales channel yet. */
    public const NOT_ON_CHANNEL = 'CADENCE_NOT_ON_CHANNEL';

    /** The previous step in the same direction is less than the minimum interval old. */
    public const WITHIN_INTERVAL = 'CADENCE_WITHIN_24H';

    private function __construct(
        public readonly bool $allowed,
        public readonly ?string $code,
        public readonly ?StepRecord $lastStep,
    ) {
    }

    public static function allow(?StepRecord $lastStep = null): self
    {
        return new self(true, null, $lastStep);
    }

    public static function hold(string $code, StepRecord $lastStep): self
    {
        return new self(false, $code, $lastStep);
    }
}
