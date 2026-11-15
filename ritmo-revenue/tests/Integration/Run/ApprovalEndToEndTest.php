<?php

declare(strict_types=1);

namespace Ritmo\Tests\Integration\Run;

use PHPUnit\Framework\TestCase;
use Ritmo\Application\Approval\PlanEntry;
use Ritmo\Application\Schedule\LaunchStatus;
use Ritmo\Domain\Model\PropertyMode;
use Ritmo\Domain\Operations\RunKind;
use Ritmo\Domain\Write\ApprovalConfig;
use Ritmo\Tests\Integration\Run\Support\RunWorld;

/**
 * Approval mode against the simulator: the plan waits for a person and nothing is written; once
 * approved (whole or in part), the approved write builds the plan again from fresh reads and
 * sends only the approved entries, in slices.
 */
final class ApprovalEndToEndTest extends TestCase
{
    public function testNothingIsWrittenBeforeApprovalAndTheApprovedPlanIsWrittenAfter(): void
    {
        $world = new RunWorld(PropertyMode::Approval);

        $proposed = $world->run();

        self::assertSame(LaunchStatus::Completed, $proposed->status, $proposed->note);
        $write = $world->summary($proposed->runId)['write'] ?? [];
        self::assertTrue($write['proposed'] ?? false, json_encode($write));
        self::assertSame(0, $write['writes_sent']);
        self::assertSame([], $world->writes(), 'nothing is written while the plan waits');
        $pending = $world->stack->approvalDesk()->pending(RunWorld::PROPERTY, new ApprovalConfig());
        self::assertCount(1, $pending);

        $world->stack->approvalDesk()->approve(RunWorld::PROPERTY, $pending[0]['id'], RunWorld::OWNER, null, new ApprovalConfig());
        self::assertSame([], $world->writes(), 'approving schedules the write; it does not write');
        $written = $world->run(RunKind::ApprovedWrite);

        self::assertSame(LaunchStatus::Completed, $written->status, $written->note);
        $summary = $world->summary($written->runId);
        self::assertGreaterThan(0, $summary['write']['writes_sent'] ?? 0, json_encode($summary['write'] ?? []));
        self::assertStringStartsWith('user:', (string) ($summary['write']['actor'] ?? ''));
        self::assertGreaterThan(0, $summary['verify']['nights_confirmed']);
        self::assertSame('written', $world->stack->approvalRequests()->find($pending[0]['id'])['status'] ?? null);
        $changes = (new \Ritmo\Infrastructure\Db\Repository\ChangeLogRepository($world->db))->forRun((int) $written->runId);
        self::assertNotSame([], $changes);
        foreach ($changes as $change) {
            if ($change->provider->value === 'pricing') {
                self::assertNotSame('', $change->reasonCode, 'the change log says why (M4 product review)');
            }
        }
    }

    public function testTheSchedulerTickWritesTheApprovedPlanFirst(): void
    {
        $world = new RunWorld(PropertyMode::Approval);
        $world->run();
        $pending = $world->stack->approvalDesk()->pending(RunWorld::PROPERTY, new ApprovalConfig());
        $world->stack->approvalDesk()->approve(RunWorld::PROPERTY, $pending[0]['id'], RunWorld::OWNER, null, new ApprovalConfig());

        for ($tick = 0; $tick < 5 && $world->stack->approvalRequests()->find($pending[0]['id'])['status'] !== 'written'; ++$tick) {
            $world->stack->tick()->run();
            $world->later('+5 minutes');
        }

        self::assertSame('written', $world->stack->approvalRequests()->find($pending[0]['id'])['status'] ?? null);
        self::assertNotSame([], $world->writes());
        $kinds = array_column($world->stack->runs()->latest(RunWorld::PROPERTY, 10), 'kind');
        self::assertContains('approved_write', $kinds);
    }

    public function testAPartialApprovalWritesOnlyTheNightsApproved(): void
    {
        $world = new RunWorld(PropertyMode::Approval);
        $world->run();
        $pending = $world->stack->approvalDesk()->pending(RunWorld::PROPERTY, new ApprovalConfig());
        $standard = array_values(array_filter($pending[0]['entries'], static fn (PlanEntry $e): bool => $e->roomCode === 'STD'));
        self::assertNotSame([], $standard);

        $world->stack->approvalDesk()->approve(RunWorld::PROPERTY, $pending[0]['id'], RunWorld::OWNER, array_map(static fn (PlanEntry $e): string => $e->key(), $standard), new ApprovalConfig());
        $world->run(RunKind::ApprovedWrite);

        $paths = array_unique(array_map(static fn (array $w): string => $w['path'], array_filter($world->apis->writeBodies(), static fn (array $w): bool => $w['path'] !== '/pricelabs/v1/refresh_listing')));
        self::assertNotSame([], $paths);
        foreach ($paths as $path) {
            self::assertStringContainsString('901001___902001', $path, 'only the approved room was written');
        }
    }

    /**
     * Regression (M4 product review): approved nights left out are never skipped in silence. A
     * recalculation refused by the pricing system counts against the listing's quota; the next
     * approved write of the same routine leaves that room out, and the request reads "written in
     * part", with a report line and an alert naming the room. The approval_pending alert ends with
     * the decision.
     */
    public function testApprovedNightsLeftOutByTheRecalculationLimitAreReported(): void
    {
        $world = new RunWorld(PropertyMode::Approval);
        $world->run();
        self::assertNotSame([], (new \Ritmo\Infrastructure\Schedule\AlertLedger($world->db))->active(RunWorld::PROPERTY, ['approval_pending']));
        $first = $world->stack->approvalDesk()->pending(RunWorld::PROPERTY, new ApprovalConfig())[0]['id'];
        $world->stack->approvalDesk()->approve(RunWorld::PROPERTY, $first, RunWorld::OWNER, null, new ApprovalConfig());
        self::assertSame([], (new \Ritmo\Infrastructure\Schedule\AlertLedger($world->db))->active(RunWorld::PROPERTY, ['approval_pending']), 'decided: nothing waits any more');

        $world->apis->fault('POST', '/pricelabs/v1/refresh_listing', 400);
        $world->run(RunKind::ApprovedWrite);
        self::assertContains($world->stack->approvalRequests()->find($first)['status'] ?? null, ['written_partly', 'write_failed'], 'a refused recalculation is not a full write');

        $world->later('+15 minutes');
        $world->run();
        $second = $world->stack->approvalDesk()->pending(RunWorld::PROPERTY, new ApprovalConfig())[0]['id'];
        $world->stack->approvalDesk()->approve(RunWorld::PROPERTY, $second, RunWorld::OWNER, null, new ApprovalConfig());
        $written = $world->run(RunKind::ApprovedWrite);

        $summary = $world->summary($written->runId);
        self::assertNotSame([], $summary['write']['not_recalculated'] ?? [], json_encode($summary['write'] ?? []));
        self::assertSame('written_partly', $world->stack->approvalRequests()->find($second)['status'] ?? null);
        $codes = array_column((array) ($summary['report']['items'] ?? []), 'code');
        self::assertContains('not_recalculated', $codes, json_encode($summary['report'] ?? []));
        self::assertNotSame([], (new \Ritmo\Infrastructure\Schedule\AlertLedger($world->db))->active(RunWorld::PROPERTY, ['recalc_quota_exhausted']));

        // a day later the quota is free again: the room is written and its alert resolves
        $world->later('+25 hours');
        $world->run();
        $third = $world->stack->approvalDesk()->pending(RunWorld::PROPERTY, new ApprovalConfig())[0]['id'];
        $world->stack->approvalDesk()->approve(RunWorld::PROPERTY, $third, RunWorld::OWNER, null, new ApprovalConfig());
        $world->run(RunKind::ApprovedWrite);
        self::assertSame('written', $world->stack->approvalRequests()->find($third)['status'] ?? null);
        self::assertSame([], (new \Ritmo\Infrastructure\Schedule\AlertLedger($world->db))->active(RunWorld::PROPERTY, ['recalc_quota_exhausted']));
    }

    public function testAPersonWithoutTheApprovalCapabilityCannotApprove(): void
    {
        $world = new RunWorld(PropertyMode::Approval);
        $world->run();
        $pending = $world->stack->approvalDesk()->pending(RunWorld::PROPERTY, new ApprovalConfig());

        $this->expectException(\Ritmo\Application\Approval\ApprovalRefused::class);
        $world->stack->approvalDesk()->approve(RunWorld::PROPERTY, $pending[0]['id'], 99, null, new ApprovalConfig());
    }
}
