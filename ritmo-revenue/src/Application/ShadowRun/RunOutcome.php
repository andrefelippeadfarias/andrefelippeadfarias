<?php

declare(strict_types=1);

namespace Ritmo\Application\ShadowRun;

/**
 * Where a request to run stands when the call returns.
 */
final class RunOutcome
{
    /** The run still has stages to go; call again to resume it. */
    public const RUNNING = 'running';

    public const COMPLETED = 'completed';

    public const FAILED = 'failed';

    /** The emergency switch stopped the run before one of its stages. */
    public const STOPPED = 'stopped';

    /** Another request holds the property's run lock; nothing was done. */
    public const BUSY = 'busy';

    /** The emergency switch is engaged; no run was started. */
    public const BLOCKED = 'blocked';

    /**
     * @param int|null    $runId  null when no run was started
     * @param string      $stage  last stage completed ('' before the first)
     * @param string|null $error  why the run failed, stopped or did not start, as a sentence safe to show
     */
    public function __construct(
        public readonly string $status,
        public readonly ?int $runId,
        public readonly string $stage = '',
        public readonly ?string $error = null,
    ) {
    }

    public function isFinished(): bool
    {
        return $this->status !== self::RUNNING;
    }

    /**
     * @return array{status: string, run_id: int|null, stage: string, error: string|null}
     */
    public function toArray(): array
    {
        return ['status' => $this->status, 'run_id' => $this->runId, 'stage' => $this->stage, 'error' => $this->error];
    }
}
