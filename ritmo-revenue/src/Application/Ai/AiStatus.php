<?php

declare(strict_types=1);

namespace Ritmo\Application\Ai;

use Ritmo\Domain\Ai\AiSettings;
use Ritmo\Domain\Ai\AiStopped;
use Ritmo\Domain\Ai\MonthlyUsage;

/**
 * Whether the assistant may be asked now and, if not, why (an AiStopped reason): off, no licence
 * that allows it (Application\Licence), no key, the monthly cap reached, or a model the key
 * cannot use.
 */
final class AiStatus
{
    public function __construct(
        public readonly AiSettings $settings,
        public readonly bool $hasKey,
        public readonly MonthlyUsage $usage,
        public readonly bool $licensed = true,
    ) {
    }

    public function blocker(): ?string
    {
        return match (true) {
            !$this->settings->enabled => AiStopped::DISABLED,
            !$this->licensed => AiStopped::LICENCE,
            !$this->hasKey => AiStopped::NO_KEY,
            $this->usage->capReached($this->settings->monthlyCapUsd) => AiStopped::BUDGET,
            !$this->settings->modelAvailable() => AiStopped::MODEL_UNAVAILABLE,
            default => null,
        };
    }

    public function isReady(): bool
    {
        return $this->blocker() === null;
    }
}
