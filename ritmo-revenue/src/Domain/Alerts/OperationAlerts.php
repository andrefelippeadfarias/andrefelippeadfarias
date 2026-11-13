<?php

declare(strict_types=1);

namespace Ritmo\Domain\Alerts;

use DateTimeImmutable;
use Ritmo\Domain\Support\Days;

/**
 * The alerts of the operation: writes that failed, quota, connectors, manual changes found and
 * the scheduler. Facts only (codes, numbers, instants); never a response body or a credential.
 */
final class OperationAlerts
{
    /**
     * A write run stopped at its first failure (spec 03: 400, 429, 5xx or a timeout).
     *
     * @param int|null $httpStatus null for a timeout or a transport failure
     */
    public static function writeFailed(string $propertyId, string $provider, ?int $httpStatus, int $slice, int $entriesLeft, int $groupsOpen, DateTimeImmutable $at): AlertDraft
    {
        return new AlertDraft(
            AlertCode::WriteFailed,
            $propertyId,
            [$provider],
            $groupsOpen > 0
                ? 'A write stopped at its first failure with groups left open: the next run reads the systems again and completes them first.'
                : 'A write stopped at its first failure: the next run reads the systems again and sends only what is missing.',
            ['provider' => $provider, 'http_status' => $httpStatus, 'failure' => $httpStatus === null ? 'timeout' : 'http', 'slice' => $slice, 'entries_left' => $entriesLeft, 'groups_open' => $groupsOpen],
            evidenceAt: $at,
        );
    }

    /**
     * Raised by every run while a deletion-and-rewrite group stays open: its nights have no date
     * rule until the rewrite is in force (task rule 9(e)).
     *
     * @param list<string> $nights "room|YYYY-MM-DD" of the nights without their rule
     */
    public static function groupsOpen(string $propertyId, int $groups, array $nights, string $mode, DateTimeImmutable $at): AlertDraft
    {
        return new AlertDraft(
            AlertCode::WriteFailed,
            $propertyId,
            ['pricing'],
            $mode === 'approval'
                ? 'Nights are without their date rule since a write stopped between a deletion and its rewrite: approve the repair proposed first.'
                : 'Nights are without their date rule since a write stopped between a deletion and its rewrite: the next run that may write repairs them first.',
            ['provider' => 'pricing', 'http_status' => null, 'failure' => 'group_open', 'slice' => 0, 'entries_left' => 0, 'groups_open' => $groups, 'nights' => array_slice($nights, 0, 20), 'mode' => $mode],
            evidenceAt: $at,
        );
    }

    public static function recalcQuotaExhausted(string $propertyId, string $roomCode): AlertDraft
    {
        return new AlertDraft(
            AlertCode::RecalcQuotaExhausted,
            $propertyId,
            [$roomCode],
            'The recalculation limit of this room is used up: its changes wait and are reported as not confirmed.',
            ['room' => $roomCode],
            $roomCode,
        );
    }

    /**
     * A read failed after its retries. The PMS down means the bookings are not watched.
     */
    public static function connectorDown(string $propertyId, string $provider, bool $isPms, bool $authFailed): AlertDraft
    {
        $message = $isPms
            ? 'Vigilance blind: bookings and cancellations are not being watched, the PMS could not be read.'
            : 'The pricing system could not be read.';

        return new AlertDraft(
            AlertCode::ConnectorDown,
            $propertyId,
            [$provider],
            $authFailed ? $message . ' The connection was refused: reconnect it.' : $message,
            ['provider' => $provider, 'reason' => $authFailed ? 'auth_failed' : 'unreachable'],
            severity: $isPms ? AlertSeverity::Critical : AlertSeverity::Warning,
        );
    }

    /**
     * Someone changed a night by hand in one of the systems; the night is frozen.
     *
     * @param array<string, scalar|null> $before the fields as read before
     * @param array<string, scalar|null> $after  the fields as read now
     */
    public static function manualChange(string $propertyId, string $provider, string $roomCode, DateTimeImmutable $night, array $before, array $after, DateTimeImmutable $at): AlertDraft
    {
        return new AlertDraft(
            AlertCode::ManualChangeDetected,
            $propertyId,
            [$roomCode, Days::key($night), $provider],
            'A night was changed outside the plugin: it is frozen in both systems and never overwritten automatically.',
            ['provider' => $provider, 'before' => $before, 'after' => $after],
            $roomCode,
            $night,
            $at,
        );
    }

    /**
     * @param array<string, scalar|null> $before
     * @param array<string, scalar|null> $after
     */
    public static function listingChanged(string $propertyId, string $listingRef, array $before, array $after, DateTimeImmutable $at): AlertDraft
    {
        return new AlertDraft(
            AlertCode::ListingChangedManually,
            $propertyId,
            [$listingRef],
            'The minimum, base or maximum of a listing was changed outside the plugin.',
            ['listing' => $listingRef, 'before' => $before, 'after' => $after],
            evidenceAt: $at,
        );
    }

    public static function runLate(string $propertyId, string $slotLocal, int $delaySeconds): AlertDraft
    {
        return new AlertDraft(
            AlertCode::RunLate,
            $propertyId,
            [],
            sprintf('A run started %d minutes after its time.', intdiv($delaySeconds, 60)),
            ['slot' => $slotLocal, 'delay_minutes' => intdiv($delaySeconds, 60)],
        );
    }

    public static function runMissed(string $propertyId, DateTimeImmutable $first, DateTimeImmutable $last): AlertDraft
    {
        return new AlertDraft(
            AlertCode::RunMissed,
            $propertyId,
            [(string) $first->getTimestamp()],
            'Scheduled runs of past days did not run (the site was not running them).',
            ['from' => AlertDraft::instant($first), 'to' => AlertDraft::instant($last)],
            evidenceAt: $last,
        );
    }

    /**
     * Site level: nothing runs the scheduler (WP-Cron off without a system cron, or no traffic).
     */
    public static function schedulerStalled(?DateTimeImmutable $lastTick, DateTimeImmutable $oldestDue): AlertDraft
    {
        return new AlertDraft(
            AlertCode::SchedulerStalled,
            '',
            [],
            'Scheduled runs are waiting and the scheduler has not run: check that WP-Cron runs, or call wp-cron.php from a system cron every 5 minutes.',
            ['last_tick' => AlertDraft::instant($lastTick), 'oldest_due' => AlertDraft::instant($oldestDue)],
        );
    }

    /**
     * After a long stop the movement read was capped: bookings changed in the gap were not read.
     */
    public static function vigilanceGap(string $propertyId, DateTimeImmutable $from, DateTimeImmutable $to): AlertDraft
    {
        return new AlertDraft(
            AlertCode::VigilanceGap,
            $propertyId,
            [(string) $from->getTimestamp()],
            'Bookings changed during a long stop were not read: check them in the PMS.',
            ['from' => AlertDraft::instant($from), 'to' => AlertDraft::instant($to)],
            evidenceAt: $to,
        );
    }
}
