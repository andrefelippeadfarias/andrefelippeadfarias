<?php

declare(strict_types=1);

namespace Ritmo\Domain\Report\RunLines;

/**
 * The lines of a run report, in the order the report stage writes them: what the watch found,
 * then what became of the plan (shadow, approval, refusal or the write), then holds, refusals,
 * skipped PMS changes, overdue steps and the market's age.
 */
final class RunReportItems
{
    private function __construct()
    {
    }

    /**
     * @param int $staleAfterDays market prices older than this many days get a line (0: never)
     *
     * @return list<RunLine>
     */
    public static function of(RunFacts $f, int $staleAfterDays = 0): array
    {
        if ($f->light && $f->lightNights === 0) {
            return [RunLine::of(RunLineCode::LightIdle)];
        }
        $lines = [];
        $flag = static function (bool $when, RunLineCode $code, array $params = []) use (&$lines): void {
            if ($when) {
                $lines[] = RunLine::of($code, $params);
            }
        };
        $flag($f->syncNow, RunLineCode::SyncNow);
        $flag($f->securityFindings > 0, RunLineCode::SecurityFindings, ['count' => $f->securityFindings]);
        $flag($f->overbooked > 0, RunLineCode::Overbooked, ['count' => $f->overbooked]);
        $flag($f->waveHold, RunLineCode::WaveHold);
        $lines = [...$lines, ...self::destiny($f)];
        $flag($f->held > 0, RunLineCode::Held, ['count' => $f->held]);
        $flag($f->rejected + $f->pmsRejected > 0, RunLineCode::ValidatorRefused, ['count' => $f->rejected, 'pms' => $f->pmsRejected]);
        $flag($f->mergeConflicts > 0, RunLineCode::StayMergeConflict, ['count' => $f->mergeConflicts]);
        foreach ($f->pmsSkipped as $reason => $count) {
            $flag($count > 0, RunLineCode::PmsSkipped, ['count' => $count, 'reason' => self::code((string) $reason)]);
        }
        $flag($f->overdue > 0, RunLineCode::DeliveryOverdue, ['count' => $f->overdue]);
        $flag($staleAfterDays > 0 && $f->marketAgeDays !== null && $f->marketAgeDays > $staleAfterDays, RunLineCode::MarketStale, ['days' => (int) $f->marketAgeDays]);

        return $lines;
    }

    /**
     * @return list<RunLine>
     */
    private static function destiny(RunFacts $f): array
    {
        if ($f->shadow) {
            return [RunLine::of(RunLineCode::ShadowWouldWrite, ['items' => $f->wouldWrite, 'pms' => $f->pmsWouldWrite])];
        }
        if ($f->proposed) {
            return [RunLine::of(RunLineCode::WaitingApproval, ['entries' => $f->entries])];
        }
        if ($f->refused !== null) {
            return [RunLine::of(RunLineCode::GateRefused, ['reason' => self::code($f->refused)])];
        }
        $lines = [RunLine::of(RunLineCode::Written, ['calls' => $f->callsSent, 'confirmed' => $f->confirmed, 'pms_confirmed' => $f->pmsConfirmed])];
        if ($f->stop !== null) {
            $lines[] = RunLine::of(RunLineCode::WriteStopped, ['reason' => self::code($f->stop)]);
        }

        return $lines;
    }

    /**
     * A reason as a code the line may carry; anything else (never expected) becomes "other", so a
     * strange value can neither break the report nor reach it.
     */
    private static function code(string $reason): string
    {
        return preg_match('/^[a-z][a-z0-9_]{0,63}$/', $reason) === 1 ? $reason : 'other';
    }
}
