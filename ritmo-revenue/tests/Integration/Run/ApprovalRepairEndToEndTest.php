<?php

declare(strict_types=1);

namespace Ritmo\Tests\Integration\Run;

use PHPUnit\Framework\TestCase;
use Ritmo\Infrastructure\Schedule\AlertLedger;
use Ritmo\Application\Approval\PlanEntry;
use Ritmo\Domain\Model\PropertyMode;
use Ritmo\Domain\Operations\RunKind;
use Ritmo\Domain\Write\ApprovalConfig;
use Ritmo\Tests\Integration\Run\Support\RunWorld;
use Ritmo\Tests\Integration\Write\Support\SimWorld;

/**
 * In approval mode a deletion-and-rewrite group left open (the deletion went out, the rewrite
 * did not) is proposed for approval first and alone, stays open (and alerted) until a person
 * approves its repair, and is then written first. Review finding of the M3 correction phase: it
 * was never repaired and the night stayed without its rule.
 */
final class ApprovalRepairEndToEndTest extends TestCase
{
    private const NIGHT = '2027-03-05';

    public function testAGroupLeftOpenInApprovalModeIsProposedFirstAndWrittenOnceApproved(): void
    {
        $world = new RunWorld(PropertyMode::Approval);
        self::approveAll($world);
        $world->hook->afterWrite = static function (int $writes) use ($world): void {
            if ($writes === 3) {
                $world->switch->engage();
            }
        };
        $stopped = $world->run(RunKind::ApprovedWrite);
        self::assertSame(1, $world->summary($stopped->runId)['write']['broken_groups'] ?? null, 'the deletion went out, its rewrite did not');
        self::assertArrayNotHasKey(self::NIGHT, $world->apis->world()['overrides'][SimWorld::STD] ?? [], 'the night is without its rule');
        $world->hook->afterWrite = null;
        $world->switch->release();

        foreach ([1, 2] as $round) {
            $world->later('+20 minutes');
            $proposal = $world->run();
            $summary = $world->summary($proposal->runId);
            self::assertSame(1, $summary['watch']['groups_open'] ?? null, "round $round: the group is still open");
            self::assertTrue($summary['write']['repair'] ?? false, "round $round: the repair is what is proposed");
            self::assertSame(1, (new AlertLedger($world->db))->latestByKey('write_failed:inn:pricing')?->context['groups_open'] ?? null, "round $round: the alert is raised again");
        }
        $pending = $world->stack->approvalDesk()->pending(RunWorld::PROPERTY, new ApprovalConfig());
        self::assertCount(1, $pending);
        self::assertSame(['STD|' . self::NIGHT], array_values(array_unique(array_map(static fn (PlanEntry $e): string => $e->roomCode . "|" . $e->subject, $pending[0]['entries']))), 'only the repair, alone');

        $world->stack->approvalDesk()->approve(RunWorld::PROPERTY, $pending[0]['id'], RunWorld::OWNER, null, new ApprovalConfig());
        $before = count($world->writes());
        $repair = $world->run(RunKind::ApprovedWrite);

        $written = $world->summary($repair->runId)['write'] ?? [];
        self::assertTrue($written['repair'] ?? false, json_encode($written));
        self::assertGreaterThan(0, $written['writes_sent'] ?? 0);
        self::assertSame('POST /pricelabs/v1/listings/' . SimWorld::STD . '/overrides', array_values(array_filter(array_slice($world->writes(), $before), static fn (string $l): bool => !str_ends_with($l, '/refresh_listing')))[0] ?? '');
        self::assertArrayHasKey(self::NIGHT, $world->apis->world()['overrides'][SimWorld::STD] ?? [], 'the night has its rule again');

        $world->later('+20 minutes');
        $next = $world->run();
        self::assertSame(0, $world->summary($next->runId)['watch']['groups_open'] ?? null, 'the group is closed');
        self::assertFalse($world->summary($next->runId)['write']['repair'] ?? true, 'the next plan is the normal one');
    }

    private static function approveAll(RunWorld $world): void
    {
        $world->run();
        $pending = $world->stack->approvalDesk()->pending(RunWorld::PROPERTY, new ApprovalConfig());
        self::assertCount(1, $pending);
        $world->stack->approvalDesk()->approve(RunWorld::PROPERTY, $pending[0]['id'], RunWorld::OWNER, null, new ApprovalConfig());
    }
}
