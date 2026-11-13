<?php

declare(strict_types=1);

namespace Ritmo\Application\Run;

use Ritmo\Application\Market\MarketSettings;
use Ritmo\Application\ShadowRun\AfterWriteStage;
use Ritmo\Application\ShadowRun\PlanItemStatus;
use Ritmo\Application\ShadowRun\RunContext;
use Ritmo\Application\ShadowRun\StageResult;
use Ritmo\Domain\Config\InvalidConfig;
use Ritmo\Domain\Model\PropertyMode;
use Ritmo\Domain\Operations\RunKind;
use Ritmo\Domain\Report\RunLines\RunFacts;
use Ritmo\Domain\Report\RunLines\RunLine;
use Ritmo\Domain\Report\RunLines\RunReportItems;

/**
 * The report of the run, kept with it (operations spec 9, O44-O46; reports spec 2.5): the manual
 * sync of tonight and tomorrow first when the run wrote them, then security and overbooking, then
 * where every item went (written and confirmed, held, waiting for approval, refused, or what
 * shadow would write). The lines are stored as codes with counts and reason codes ("items"), so
 * each reader sees them in their own language; no guest data, bookings only by count. A full run
 * also runs the end checks (old market prices, gaps of the ruler, steps overdue at the channel,
 * the review due).
 */
final class ReportStage implements AfterWriteStage
{
    public const NAME = 'report';

    public function __construct(private readonly RunEndChecks $checks)
    {
    }

    public function name(): string
    {
        return self::NAME;
    }

    public function run(RunContext $context): StageResult
    {
        $summary = $context->summary;
        $verify = (array) ($summary['verify'] ?? []);
        $light = $context->kind === RunKind::Light->value;
        $marketAge = $light ? null : $this->checks->check($context, $verify);
        $items = RunReportItems::of(self::facts($context, $marketAge), $light ? 0 : self::staleAfter($context));

        return new StageResult(['items' => array_map(static fn (RunLine $line): array => $line->toArray(), $items)], []);
    }

    private static function facts(RunContext $context, ?int $marketAge): RunFacts
    {
        $summary = $context->summary;
        $watch = (array) ($summary['watch'] ?? []);
        $validate = (array) ($summary['validate'] ?? []);
        $write = (array) ($summary['write'] ?? []);
        $verify = (array) ($summary['verify'] ?? []);

        return new RunFacts(
            light: $context->kind === RunKind::Light->value,
            lightNights: (int) ($watch['light']['nights'] ?? 0),
            shadow: $context->mode === PropertyMode::Shadow,
            syncNow: ($write['sync_now'] ?? false) === true,
            securityFindings: (int) ($watch['security_findings'] ?? 0),
            overbooked: (int) ($watch['overbooked'] ?? 0),
            waveHold: ($watch['wave_hold'] ?? false) === true,
            wouldWrite: (int) ($validate[PlanItemStatus::WOULD_WRITE] ?? 0),
            pmsWouldWrite: (int) ($write['pms_would_write'] ?? 0),
            proposed: ($write['proposed'] ?? false) === true,
            entries: (int) ($write['entries'] ?? 0),
            refused: isset($write['refused']) ? (string) $write['refused'] : null,
            callsSent: (int) ($write['writes_sent'] ?? 0),
            confirmed: (int) ($verify['nights_confirmed'] ?? 0),
            pmsConfirmed: (int) ($verify['pms_nights_confirmed'] ?? 0),
            stop: isset($write['stop']) ? (string) $write['stop'] : null,
            held: (int) ($write['held'] ?? 0),
            rejected: (int) ($validate[PlanItemStatus::REJECTED] ?? 0),
            pmsRejected: (int) ($write['pms_rejected'] ?? 0),
            overdue: (int) ($verify['delivery']['overdue'] ?? 0),
            marketAgeDays: $marketAge,
            pmsSkipped: array_map('intval', (array) ($write['pms_skipped'] ?? [])),
            mergeConflicts: (int) ($write['pms_merge_conflicts'] ?? 0),
        );
    }

    private static function staleAfter(RunContext $context): int
    {
        try {
            return MarketSettings::of($context->rules)->staleAfterDays;
        } catch (InvalidConfig) {
            return 0;
        }
    }
}
