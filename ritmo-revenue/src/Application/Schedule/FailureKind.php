<?php

declare(strict_types=1);

namespace Ritmo\Application\Schedule;

/**
 * Why a run failed, as far as the scheduler cares: only a run that could not reach a system gets
 * one retry; a run that failed while writing never does (the next slot reads again, spec 03).
 */
enum FailureKind: string
{
    case Connector = 'connector';
    case Write = 'write';
    case DayChanged = 'day_changed';
    case Other = 'other';
}
