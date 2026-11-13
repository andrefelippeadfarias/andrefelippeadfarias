<?php
declare(strict_types=1);
namespace Ritmo\Tests\Integration\Run\Stay;

use PHPUnit\Framework\TestCase;
use Ritmo\Application\Schedule\LaunchStatus;
use Ritmo\Domain\Model\PropertyMode;
use Ritmo\Infrastructure\Db\Repository\DetectedFreezeRepository;
use Ritmo\Tests\Integration\Run\Support\RunWorld;

/**
 * Regression: a PMS block that carries two rooms (one POST for the closed Saturday of every room)
 * is journaled with the room code of each PMS room id, so the verify stage, the watch of the next
 * run and the recovery after a crash all read one room per night (never "STD,GDN").
 */
final class MultiRoomPmsBlockTest extends TestCase
{
    private static function world(PropertyMode $mode = PropertyMode::Auto): RunWorld
    {
        return StayWorld::auto(['closed_days' => [StayWorld::CLOSED_SATURDAY]], $mode);
    }

    public function testAnAutonomousRunThatClosesSaturdayInTwoRoomsCompletes(): void
    {
        $world = self::world();
        $result = $world->run();
        self::assertCount(1, StayWorld::pmsBodies($world));
        self::assertCount(2, StayWorld::pmsBodies($world)[0]['body'], 'one PMS block with both rooms');
        self::assertSame(LaunchStatus::Completed, $result->status, (string) $result->note);
    }

    public function testTheNextRunDoesNotFreezeThePluginsOwnWrites(): void
    {
        $world = self::world();
        $world->run();
        $world->later('+2 minutes');
        $second = $world->run();
        $watch = $world->summary($second->runId)['watch'] ?? [];
        self::assertSame(0, $watch['nights_frozen'] ?? null, json_encode($watch));
        self::assertSame([], (new DetectedFreezeRepository($world->db))->unreleased(RunWorld::PROPERTY));
    }

    public function testAfterACrashOnThePmsBlockTheFollowingRunsStillWork(): void
    {
        $plain = self::world();
        $plain->run();
        $world = self::world();
        $world->apis->crashAfterWrites(count($plain->writes()));
        $world->run();
        $statuses = [];
        for ($i = 0; $i < 3; ++$i) {
            $world->later('+2 minutes');
            $r = $world->run();
            $statuses[] = $r->status->value . ' ' . $r->note;
        }
        self::assertSame(['completed ', 'completed ', 'completed '], $statuses);
    }

    public function testAnApprovedWriteOfTheSaturdaysOfBothRoomsCompletes(): void
    {
        $world = self::world(PropertyMode::Approval);
        $world->run();
        $pending = $world->stack->approvalDesk()->pending(RunWorld::PROPERTY, new \Ritmo\Domain\Write\ApprovalConfig());
        $pms = array_values(array_filter($pending[0]['entries'], static fn (\Ritmo\Application\Approval\PlanEntry $e): bool => $e->kind === \Ritmo\Application\Approval\PlanEntry::PMS));
        self::assertCount(4, $pms);
        $world->stack->approvalDesk()->approve(RunWorld::PROPERTY, $pending[0]['id'], RunWorld::OWNER, array_map(static fn ($e): string => $e->key(), $pms), new \Ritmo\Domain\Write\ApprovalConfig());
        $result = $world->run(\Ritmo\Domain\Operations\RunKind::ApprovedWrite);
        self::assertSame(['POST /beds24/v2/inventory/rooms/calendar'], $world->writes());
        self::assertSame(LaunchStatus::Completed, $result->status, (string) $result->note);
    }
}
