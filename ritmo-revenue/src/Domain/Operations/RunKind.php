<?php

declare(strict_types=1);

namespace Ritmo\Domain\Operations;

/**
 * The kinds of runs: the daily full run (and the light slot promoted to it), the light run, the
 * run a person starts ("Run now", a full run) and the run that writes an approved plan.
 */
enum RunKind: string
{
    case Full = 'full';
    case Light = 'light';
    case Manual = 'manual';
    case ApprovedWrite = 'approved_write';

    /**
     * True for the runs that read and decide the whole horizon.
     */
    public function isFull(): bool
    {
        return $this === self::Full || $this === self::Manual;
    }
}
