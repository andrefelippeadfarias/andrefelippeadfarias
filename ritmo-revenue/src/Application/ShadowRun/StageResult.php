<?php

declare(strict_types=1);

namespace Ritmo\Application\ShadowRun;

use Closure;

/**
 * What a stage produced: its part of the run summary, what it adds to the state carried to the
 * next stages and, optionally, the writes to make when the stage is recorded as completed.
 */
final class StageResult
{
    /**
     * @param array<string, mixed> $summary facts of the stage, shown with the run; no guest data
     * @param array<string, mixed> $state   keys added to (or replacing) the state carried between stages
     * @param Closure|null         $persist writes to the plugin's own tables, run inside the stage's transaction
     */
    public function __construct(
        public readonly array $summary,
        public readonly array $state = [],
        public readonly ?Closure $persist = null,
    ) {
    }
}
