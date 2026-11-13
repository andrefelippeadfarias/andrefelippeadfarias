<?php

declare(strict_types=1);

namespace Ritmo\Application\Write\Execute;

use Ritmo\Application\Write\Journal\OpStatus;
use Ritmo\Application\Write\Schedule\WriteStep;
use Ritmo\Contracts\Exception\AuthFailed;
use Ritmo\Contracts\Exception\ConnectorException;
use Ritmo\Contracts\Exception\NotReady;
use Ritmo\Contracts\Exception\RateLimited;
use Ritmo\Contracts\Exception\WriteBlocked;
use Ritmo\Contracts\WriteOperation;
use Ritmo\Domain\Support\Days;
use Throwable;

/**
 * Sends one step through the connector of its system, and says what a failure means for the run:
 * every failure stops it (no retry, no next step); a refusal stored nothing, a lost answer or a
 * server error may have applied (unknown: a later run reads it back before anything is resent).
 */
final class StepSender
{
    /**
     * @throws ConnectorException|WriteBlocked
     */
    public function send(WriteRun $run, WriteStep $step): void
    {
        $token = $run->issued->token;
        match ($step->operation) {
            WriteOperation::OverridesWrite => $run->pricing->writeOverrides($token, $step->target(), $step->payload),
            WriteOperation::OverridesDelete => $run->pricing->deleteOverrides($token, $step->target(), array_map(static fn (string $d) => Days::date($d), $step->payload)),
            WriteOperation::Recalculation => $run->pricing->requestRecalculation($token, $step->target()),
            WriteOperation::ListingUpdate => $run->pricing->updateListing($token, $step->target(), $step->payload),
            WriteOperation::PmsCalendar => $run->pms === null
                ? throw WriteBlocked::forRequest('POST', 'PMS calendar', 'the run has no PMS connector')
                : $run->pms->writeCalendar($token, $step->payload),
        };
    }

    /**
     * @return array{0: OpStatus, 1: StopReason, 2: int|null} journal status, why the run stops, HTTP status
     */
    public static function classify(Throwable $failure): array
    {
        $status = $failure instanceof ConnectorException ? $failure->httpStatus() : null;

        return match (true) {
            $failure instanceof NotReady => [OpStatus::NotReady, StopReason::NotReady, $status],
            $failure instanceof RateLimited => [OpStatus::RateLimited, StopReason::RateLimited, $status],
            $failure instanceof AuthFailed => [OpStatus::Rejected, StopReason::AuthFailed, $status],
            $failure instanceof WriteBlocked => [OpStatus::Rejected, StopReason::Blocked, null],
            $status === null => [OpStatus::Unknown, StopReason::Unknown, null],
            $status >= 500 || $status === 408 => [OpStatus::Unknown, StopReason::UpstreamError, $status],
            $status >= 400 => [OpStatus::Rejected, StopReason::Rejected, $status],
            default => [OpStatus::Unknown, StopReason::Unknown, $status],
        };
    }
}
