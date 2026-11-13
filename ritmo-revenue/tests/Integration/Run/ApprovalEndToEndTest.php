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

    public function testAPersonWithoutTheApprovalCapabilityCannotApprove(): void
    {
        $world = new RunWorld(PropertyMode::Approval);
        $world->run();
        $pending = $world->stack->approvalDesk()->pending(RunWorld::PROPERTY, new ApprovalConfig());

        $this->expectException(\Ritmo\Application\Approval\ApprovalRefused::class);
        $world->stack->approvalDesk()->approve(RunWorld::PROPERTY, $pending[0]['id'], 99, null, new ApprovalConfig());
    }
}
