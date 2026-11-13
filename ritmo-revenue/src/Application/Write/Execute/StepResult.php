<?php

declare(strict_types=1);

namespace Ritmo\Application\Write\Execute;

use Ritmo\Application\Write\Journal\OpStatus;
use Ritmo\Application\Write\Schedule\WriteStep;

/**
 * How one step of the run ended, with the reason when it was not written as intended.
 */
final class StepResult
{
    public function __construct(
        public readonly WriteStep $step,
        public readonly OpStatus $status,
        public readonly string $reason = '',
        public readonly ?int $httpStatus = null,
    ) {
    }

    public function with(OpStatus $status, string $reason = ''): self
    {
        return new self($this->step, $status, $reason === '' ? $this->reason : $reason, $this->httpStatus);
    }
}
