<?php

declare(strict_types=1);

namespace Ritmo\Tests\Integration\Run;

use PHPUnit\Framework\TestCase;
use Ritmo\Application\Approval\PlanEntry;
use Ritmo\Domain\Model\PropertyMode;
use Ritmo\Domain\Operations\ModesConfig;
use Ritmo\Domain\Operations\RunKind;
use Ritmo\Domain\Write\ApprovalConfig;
use Ritmo\Infrastructure\Db\Repository\OperationEventRepository;
use Ritmo\Tests\Integration\Run\Support\RunWorld;

/**
 * An approval is good for the write it was given for, and only while nothing changed: lowering
 * the mode or engaging the emergency switch ends it, and a newer approval replaces it (the write
 * never takes an older one left behind). Review findings of the M3 correction phase.
 */
final class ApprovalLifecycleEndToEndTest extends TestCase
{
    private const STD = '901001___902001';

    public function testAnApprovalGivenBeforeTheModeWasLoweredIsNeverWrittenAfterwards(): void
    {
        $world = new RunWorld(PropertyMode::Approval);
        $id = self::proposeAndApprove($world, null);

        $world->stack->modes()->change(RunWorld::PROPERTY, PropertyMode::Shadow, RunWorld::OWNER, new ModesConfig(), '', 'stop');
        self::assertSame('lapsed', $world->stack->approvalRequests()->find($id)['status']);
        $world->later('+10 minutes');
        $world->run();
        $world->stack->modes()->change(RunWorld::PROPERTY, PropertyMode::Approval, RunWorld::OWNER, new ModesConfig(minShadowRuns: 0), '', 'resume');
        $world->run(RunKind::ApprovedWrite);

        self::assertSame([], $world->writes(), 'the approval given before the stop was not written');
        self::assertSame('lapsed', $world->stack->approvalRequests()->find($id)['status']);
    }

    public function testANewPartialApprovalWritesOnlyWhatItApproved(): void
    {
        $world = new RunWorld(PropertyMode::Approval);
        $first = self::proposeAndApprove($world, null);
        $world->stack->modes()->change(RunWorld::PROPERTY, PropertyMode::Shadow, RunWorld::OWNER, new ModesConfig(), '', 'stop');
        $world->stack->modes()->change(RunWorld::PROPERTY, PropertyMode::Approval, RunWorld::OWNER, new ModesConfig(minShadowRuns: 0), '', 'resume');
        $world->later('+10 minutes');

        $second = self::proposeAndApprove($world, 'GDN');
        for ($i = 0; $i < 4; ++$i) {
            $world->stack->tick()->run();
            $world->later('+5 minutes');
        }

        $paths = array_map(static fn (array $w): string => $w['path'], $world->apis->writeBodies());
        self::assertNotSame([], $paths, 'the approval of the garden room was written');
        self::assertSame([], array_values(array_filter($paths, static fn (string $p): bool => str_contains($p, self::STD))), 'nothing of the room approved only before the stop');
        self::assertSame('lapsed', $world->stack->approvalRequests()->find($first)['status']);
        self::assertSame('written', $world->stack->approvalRequests()->find($second)['status']);
    }

    public function testEngagingTheEmergencySwitchEndsTheApprovalsNotWrittenYet(): void
    {
        $world = new RunWorld(PropertyMode::Approval);
        $id = self::proposeAndApprove($world, null);

        self::assertTrue($world->stack->emergency()->set(true, 'Screen', 'user:' . RunWorld::OWNER));
        self::assertSame(['lapsed', 'emergency_switch'], [$world->stack->approvalRequests()->find($id)['status'], $world->stack->approvalRequests()->find($id)['expiry_reason']]);
        $world->run(RunKind::ApprovedWrite);
        self::assertFalse($world->stack->emergency()->set(false, 'Screen', 'user:' . RunWorld::OWNER));
        $world->run(RunKind::ApprovedWrite);

        self::assertSame([], $world->writes(), 'nothing approved before the emergency is written after it');
        $events = $world->stack->events()->latest('', OperationEventRepository::EMERGENCY, 10);
        self::assertCount(2, $events, 'one event per real change of the switch');
        $world->stack->emergency()->set(false, 'Screen', 'user:' . RunWorld::OWNER);
        self::assertCount(2, $world->stack->events()->latest('', OperationEventRepository::EMERGENCY, 10), 'releasing a released switch records nothing');
    }

    /**
     * A run in approval mode proposes its plan and the owner approves all of it, or the entries of
     * one room only.
     */
    private static function proposeAndApprove(RunWorld $world, ?string $room): int
    {
        $world->run();
        $pending = $world->stack->approvalDesk()->pending(RunWorld::PROPERTY, new ApprovalConfig());
        self::assertCount(1, $pending);
        $keys = null;
        if ($room !== null) {
            $keys = array_values(array_map(static fn (PlanEntry $e): string => $e->key(), array_filter($pending[0]['entries'], static fn (PlanEntry $e): bool => $e->roomCode === $room)));
            self::assertNotSame([], $keys);
        }
        $world->stack->approvalDesk()->approve(RunWorld::PROPERTY, $pending[0]['id'], RunWorld::OWNER, $keys, new ApprovalConfig());

        return $pending[0]['id'];
    }
}
