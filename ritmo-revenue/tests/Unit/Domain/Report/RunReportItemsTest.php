<?php

declare(strict_types=1);

namespace Ritmo\Tests\Unit\Domain\Report;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Ritmo\Domain\Report\RunLines\RunFacts;
use Ritmo\Domain\Report\RunLines\RunLine;
use Ritmo\Domain\Report\RunLines\RunLineCode;
use Ritmo\Domain\Report\RunLines\RunReportItems;

final class RunReportItemsTest extends TestCase
{
    public function testAnIdleLightRunIsOneLine(): void
    {
        self::assertSame([['code' => 'light_idle', 'params' => []]], self::arrays(RunReportItems::of(new RunFacts(light: true))));
    }

    public function testShadowRunsSayWhatWouldBeWrittenInBothSystems(): void
    {
        $lines = self::arrays(RunReportItems::of(new RunFacts(syncNow: true, securityFindings: 2, wouldWrite: 14, pmsWouldWrite: 3, rejected: 1, pmsRejected: 2)));
        self::assertSame([
            ['code' => 'sync_now', 'params' => []],
            ['code' => 'security_findings', 'params' => ['count' => 2]],
            ['code' => 'shadow_would_write', 'params' => ['items' => 14, 'pms' => 3]],
            ['code' => 'validator_refused', 'params' => ['count' => 1, 'pms' => 2]],
        ], $lines);
    }

    public function testAWriteThatStoppedAndSkippedPmsChanges(): void
    {
        $lines = self::arrays(RunReportItems::of(new RunFacts(shadow: false, callsSent: 4, confirmed: 9, pmsConfirmed: 2, stop: 'token_expired', held: 3, overdue: 1, marketAgeDays: 9, pmsSkipped: ['pricing_first' => 2, 'already_at_target' => 0], mergeConflicts: 1), 7));
        self::assertSame(['written', 'write_stopped', 'held', 'stay_merge_conflict', 'pms_skipped', 'delivery_overdue', 'market_stale'], array_column($lines, 'code'));
        self::assertSame(['calls' => 4, 'confirmed' => 9, 'pms_confirmed' => 2], $lines[0]['params']);
        self::assertSame(['count' => 2, 'reason' => 'pricing_first'], $lines[4]['params']);
        self::assertSame(['days' => 9], $lines[6]['params']);
    }

    public function testApprovalAndRefusalAreTheirOwnDestiny(): void
    {
        self::assertSame('waiting_approval', RunReportItems::of(new RunFacts(shadow: false, proposed: true, entries: 12))[0]->code->value);
        $refused = RunReportItems::of(new RunFacts(shadow: false, refused: 'Kill Switch!'))[0];
        self::assertSame(['reason' => 'other'], $refused->params);
    }

    public function testParametersAreCountsAndCodesOnly(): void
    {
        foreach ([[RunLineCode::Overbooked, ['count' => 'two']], [RunLineCode::Overbooked, ['count' => 1, 'name' => 'x']], [RunLineCode::GateRefused, ['reason' => 'guest@example.com']], [RunLineCode::Written, ['calls' => 1]]] as [$code, $params]) {
            try {
                RunLine::of($code, $params);
                self::fail('expected a refusal for ' . $code->value);
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testStoredLinesReadBackAndUnknownOnesAreDropped(): void
    {
        $line = RunLine::of(RunLineCode::PmsSkipped, ['count' => 3, 'reason' => 'changed_since_read']);
        self::assertEquals($line, RunLine::fromArray($line->toArray()));
        self::assertNull(RunLine::fromArray(['code' => 'nope', 'params' => []]));
        self::assertNull(RunLine::fromArray(['code' => 'overbooked', 'params' => ['count' => -1]]));
        self::assertNull(RunLine::fromArray(['code' => 'overbooked', 'params' => 'x']));
    }

    /**
     * @param list<RunLine> $lines
     *
     * @return list<array{code: string, params: array<string, int|string>}>
     */
    private static function arrays(array $lines): array
    {
        return array_map(static fn (RunLine $l): array => $l->toArray(), $lines);
    }
}
