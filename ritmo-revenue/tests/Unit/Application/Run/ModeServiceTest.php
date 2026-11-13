<?php

declare(strict_types=1);

namespace Ritmo\Tests\Unit\Application\Run;

use PHPUnit\Framework\TestCase;
use Ritmo\Application\Approval\CapabilityCheck;
use Ritmo\Application\Mode\ModeRefused;
use Ritmo\Application\Mode\ModeService;
use Ritmo\Domain\Alerts\OperationAlerts;
use Ritmo\Domain\Write\Hold\WaveHold;
use Ritmo\Domain\Model\PropertyMode;
use Ritmo\Domain\Operations\ModesConfig;
use Ritmo\Infrastructure\Db\Repository\OperationEventRepository;
use Ritmo\Tests\Integration\Run\Support\RunWorld;

/**
 * The operating modes: who may change them, when, with what confirmation, and the only change the
 * plugin makes on its own (back to approval after failed writes).
 */
final class ModeServiceTest extends TestCase
{
    private const RULES = 7;
    private const APPROVER = 8;

    public function testANewPropertyIsInShadowAndCannotJumpToAutonomous(): void
    {
        $world = self::world();
        self::assertSame(PropertyMode::Shadow, $world->property()->mode);

        $refusal = self::refusal(static fn () => $world->stack->modes()->change(RunWorld::PROPERTY, PropertyMode::Auto, RunWorld::OWNER, new ModesConfig(true, 0, 0, 0), RunWorld::PROPERTY));
        self::assertSame(ModeRefused::CONDITIONS, $refusal);
    }

    public function testApprovalNeedsTheConnectionsCapabilityAndTheShadowRuns(): void
    {
        $world = self::world();
        $modes = $world->stack->modes();

        self::assertSame(ModeRefused::NO_CAPABILITY, self::refusal(static fn () => $modes->change(RunWorld::PROPERTY, PropertyMode::Approval, self::RULES, new ModesConfig())), 'operations spec 6.1: the connections capability');
        self::assertSame(ModeRefused::CONDITIONS, self::refusal(static fn () => $modes->change(RunWorld::PROPERTY, PropertyMode::Approval, RunWorld::OWNER, new ModesConfig(minShadowRuns: 1))));
        $world->run();
        self::assertSame(PropertyMode::Approval, $modes->change(RunWorld::PROPERTY, PropertyMode::Approval, RunWorld::OWNER, new ModesConfig(minShadowRuns: 1), '', 'ready')->mode);
        $event = $world->stack->events()->lastTo(RunWorld::PROPERTY, OperationEventRepository::MODE, 'approval');
        self::assertSame(['shadow', 'user:' . RunWorld::OWNER, 'ready'], [$event['from'] ?? null, $event['actor'] ?? null, $event['reason'] ?? null]);
    }

    /**
     * The conditions of operations spec 6.1 the review found missing (M3 correction phase).
     */
    public function testApprovalWaitsForTestedConnectionsARecentRunNoOpenFailureAndTheSwitchReleased(): void
    {
        $config = new ModesConfig(minShadowRuns: 1);
        $change = static fn (RunWorld $world) => $world->stack->modes()->change(RunWorld::PROPERTY, PropertyMode::Approval, RunWorld::OWNER, $config);

        $untested = self::world();
        $untested->run();
        $untested->testConnections(false);
        self::assertStringContainsString('connection', self::message(static fn () => $change($untested)));

        $old = self::world();
        $old->run();
        $old->later('+25 hours');
        $old->testConnections();
        self::assertStringContainsString('24 hours', self::message(static fn () => $change($old)));

        $failing = self::world();
        $failing->run();
        $failing->stack->alerts()->raise(OperationAlerts::writeFailed(RunWorld::PROPERTY, 'pricing', 500, 1, 3, 0, $failing->clock->now()));
        self::assertStringContainsString('write_failed', self::message(static fn () => $change($failing)));

        $switched = self::world();
        $switched->run();
        $switched->switch->engage();
        self::assertStringContainsString('emergency switch', self::message(static fn () => $change($switched)));
    }

    public function testAutonomousWaitsForAWeekWithoutWriteFailuresAndForNoWaveHold(): void
    {
        $open = new ModesConfig(true, 0, 0, 0);
        $change = static fn (RunWorld $world) => $world->stack->modes()->change(RunWorld::PROPERTY, PropertyMode::Auto, RunWorld::OWNER, $open, RunWorld::PROPERTY);

        $failed = self::world();
        $failed->switchTo(PropertyMode::Approval);
        $alert = $failed->stack->alerts()->raise(OperationAlerts::writeFailed(RunWorld::PROPERTY, 'pricing', 500, 1, 3, 0, $failed->clock->now()));
        $failed->stack->alerts()->resolve((int) $alert?->id, RunWorld::OWNER, true);
        $failed->later('+2 days');
        self::assertStringContainsString('last 7 days', self::message(static fn () => $change($failed)));
        $failed->later('+6 days');
        self::assertSame(PropertyMode::Auto, $change($failed)->mode, 'a week later it may');

        $held = self::world();
        $held->switchTo(PropertyMode::Approval);
        $held->stack->holds()->hold(RunWorld::PROPERTY, new WaveHold($held->clock->now(), $held->clock->now(), ["STD|2027-03-10" => true]), ['b-1', 'b-2', 'b-3'], $held->clock->now());
        self::assertStringContainsString('cancellation wave', self::message(static fn () => $change($held)));
    }

    public function testAutonomousNeedsTheSiteSwitchTheConnectionsCapabilityTheTypedIdAndTheConditions(): void
    {
        $world = self::world();
        $world->switchTo(PropertyMode::Approval);
        $modes = $world->stack->modes();
        $open = new ModesConfig(true, 0, 0, 0);

        self::assertSame(ModeRefused::DISABLED, self::refusal(static fn () => $modes->change(RunWorld::PROPERTY, PropertyMode::Auto, RunWorld::OWNER, new ModesConfig(), RunWorld::PROPERTY)));
        self::assertSame(ModeRefused::NO_CAPABILITY, self::refusal(static fn () => $modes->change(RunWorld::PROPERTY, PropertyMode::Auto, self::RULES, $open, RunWorld::PROPERTY)));
        self::assertSame(ModeRefused::CONFIRMATION, self::refusal(static fn () => $modes->change(RunWorld::PROPERTY, PropertyMode::Auto, RunWorld::OWNER, $open, 'yes')));
        self::assertSame(ModeRefused::CONDITIONS, self::refusal(static fn () => $modes->change(RunWorld::PROPERTY, PropertyMode::Auto, RunWorld::OWNER, new ModesConfig(true, 0, 7, 0), RunWorld::PROPERTY)));
        self::assertSame(PropertyMode::Auto, $modes->change(RunWorld::PROPERTY, PropertyMode::Auto, RunWorld::OWNER, $open, RunWorld::PROPERTY)->mode);
        $auth = $modes->autonomousAuthorization($world->property());
        self::assertSame(RunWorld::OWNER, $auth?->enabledBy);
    }

    public function testAnApproverLowersTheModeAtOnceAndThePendingPlansEnd(): void
    {
        $world = self::world();
        $world->switchTo(PropertyMode::Approval);
        $world->run();
        self::assertNotSame([], $world->stack->approvalRequests()->pending(RunWorld::PROPERTY));

        self::assertSame(PropertyMode::Shadow, $world->stack->modes()->change(RunWorld::PROPERTY, PropertyMode::Shadow, self::APPROVER, new ModesConfig())->mode);
        self::assertSame([], $world->stack->approvalRequests()->pending(RunWorld::PROPERTY), 'leaving approval ends the plans waiting');
        $world->switchTo(PropertyMode::Approval);
        self::assertSame(ModeRefused::NO_CAPABILITY, self::refusal(static fn () => $world->stack->modes()->change(RunWorld::PROPERTY, PropertyMode::Shadow, 99, new ModesConfig())), 'lowering needs a capability too');
    }

    public function testAfterFailedWritesInARowAnAutonomousPropertyGoesBackToApproval(): void
    {
        $world = self::world();
        $world->switchTo(PropertyMode::Auto);
        $modes = $world->stack->modes();
        $config = new ModesConfig(demoteAfterFailedWrites: 2);

        self::assertFalse($modes->afterWrite($world->property(), true, $config));
        self::assertFalse($modes->afterWrite($world->property(), false, $config), 'a good write resets the count');
        self::assertFalse($modes->afterWrite($world->property(), true, $config));
        self::assertTrue($modes->afterWrite($world->property(), true, $config));
        self::assertSame(PropertyMode::Approval, $world->property()->mode);
        self::assertSame('system', $world->stack->events()->lastTo(RunWorld::PROPERTY, OperationEventRepository::MODE, 'approval')['actor'] ?? null);
        self::assertNull($modes->autonomousAuthorization($world->property()));
        self::assertNotSame([], $world->db->select('SELECT id FROM ' . $world->db->table('alerts') . ' WHERE code = ?', ['mode_demoted']));
    }

    private static function world(): RunWorld
    {
        $world = new RunWorld();
        $world->capabilities->grant(self::RULES, [ModeService::MANAGE_RULES]);
        $world->capabilities->grant(self::APPROVER, [CapabilityCheck::APPROVE]);

        return $world;
    }

    private static function message(callable $change): string
    {
        try {
            $change();
        } catch (ModeRefused $refused) {
            self::assertSame(ModeRefused::CONDITIONS, $refused->reason);

            return $refused->getMessage();
        }
        self::fail('The change was allowed.');
    }

    private static function refusal(callable $change): ?string
    {
        try {
            $change();
        } catch (ModeRefused $refused) {
            return $refused->reason;
        }

        return null;
    }
}
