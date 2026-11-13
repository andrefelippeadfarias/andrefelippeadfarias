<?php

declare(strict_types=1);

namespace Ritmo\Application\Run;

use Ritmo\Application\Write\Calendar\PmsWriteBatch;
use Ritmo\Domain\Plan\Assembly\AssembledPlan;

/**
 * The plan of a run with its stay writes: the assembly (the stay items merged and the accepted
 * PMS changes sealed in it) and the PMS side's verdict for the screens and the report; no batch
 * when the stay writes are off or the run is light.
 */
final class StayAssembly
{
    public function __construct(
        public readonly AssembledPlan $assembled,
        public readonly ?PmsWriteBatch $batch,
    ) {
    }

    /**
     * The counts the run report and the screens show of the PMS side.
     *
     * @return array{pms_would_write: int, pms_rejected: int, pms_frozen: int, pms_merge_conflicts: int, pms_capped: bool}
     */
    public function summary(): array
    {
        $sealed = $this->assembled->finalValidation?->plan?->plan->pmsChanges ?? [];

        return [
            'pms_would_write' => count($sealed),
            'pms_rejected' => $this->batch === null ? 0 : count($this->batch->check->refused),
            'pms_frozen' => $this->batch === null ? 0 : count($this->batch->frozenOut),
            'pms_merge_conflicts' => $this->batch?->mergeConflicts ?? 0,
            'pms_capped' => $this->batch?->check->capped ?? false,
        ];
    }
}
