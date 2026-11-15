<?php

declare(strict_types=1);

namespace Ritmo\Tests\Integration\Run;

use PHPUnit\Framework\TestCase;
use Ritmo\Application\Licence\LicenceEnforcer;
use Ritmo\Application\Licence\RepositoryModeLowering;
use Ritmo\Application\Mode\ModeRefused;
use Ritmo\Application\Schedule\LaunchStatus;
use Ritmo\Domain\Model\PropertyMode;
use Ritmo\Domain\Operations\ModesConfig;
use Ritmo\Domain\Operations\RunKind;
use Ritmo\Domain\Write\ApprovalConfig;
use Ritmo\Infrastructure\Db\Repository\OperationEventRepository;
use Ritmo\Tests\Integration\Run\Support\RunWorld;

/**
 * The licence over the run pipeline and the simulator (M5): once the licence no longer allows
 * writing, the next run starts in shadow mode, the property goes back to shadow mode for good (the
 * event in the change log, the approvals not written yet ended) and nothing is written; leaving
 * shadow mode needs a licence again. A run already open is never cut.
 */
final class LicenceEndToEndTest extends TestCase
{
    public function testALapsedLicenceStartsTheNextRunInShadowModeLowersThePropertyAndWritesNothing(): void
    {
        $world = new RunWorld(PropertyMode::Auto, ['modes' => ['autonomous_enabled' => true]]);
        $world->licence->lapse();

        $result = $world->run();

        self::assertSame(LaunchStatus::Completed, $result->status, $result->note);
        self::assertSame(PropertyMode::Shadow, $world->stack->runs()->find((int) $result->runId)['mode'] ?? null, 'the run starts in shadow mode');
        self::assertSame(PropertyMode::Shadow, $world->property()->mode, 'the property is back in shadow mode for good');
        self::assertSame([], $world->writes(), 'no write request reached the simulator');
        self::assertSame(0, $world->summary($result->runId)['write']['writes_sent'] ?? 0);
        $event = (new OperationEventRepository($world->db))->latest(RunWorld::PROPERTY, OperationEventRepository::MODE, 1)[0];
        self::assertSame(['auto', 'shadow', RepositoryModeLowering::ACTOR], [$event['from'], $event['to'], $event['actor']]);
        self::assertStringContainsString('licence', $event['reason']);

        $world->licence->activate();
        $again = $world->run();
        self::assertSame(PropertyMode::Shadow, $world->stack->runs()->find((int) $again->runId)['mode'] ?? null, 'a licence back does not raise the mode by itself');
        self::assertSame([], $world->writes());
    }

    public function testAPendingApprovalEndsWhenTheLicenceLapsesAndItsApprovedWriteSendsNothing(): void
    {
        $world = new RunWorld(PropertyMode::Approval);
        $world->run();
        $pending = $world->stack->approvalDesk()->pending(RunWorld::PROPERTY, new ApprovalConfig());
        self::assertCount(1, $pending);
        $world->stack->approvalDesk()->approve(RunWorld::PROPERTY, $pending[0]['id'], RunWorld::OWNER, null, new ApprovalConfig());

        $world->licence->remove();
        $written = $world->run(RunKind::ApprovedWrite);

        self::assertSame(PropertyMode::Shadow, $world->property()->mode);
        self::assertSame([], $world->writes(), 'an approval never writes once the licence is gone');
        self::assertSame(0, $world->summary($written->runId)['write']['writes_sent'] ?? 0);
        self::assertNotSame('written', $world->stack->approvalRequests()->find($pending[0]['id'])['status'] ?? null);
        self::assertSame([], $world->stack->approvalDesk()->pending(RunWorld::PROPERTY, new ApprovalConfig()));
    }

    public function testLeavingShadowModeNeedsALicenceThatAllowsWriting(): void
    {
        $world = new RunWorld();
        $world->testConnections();
        $world->confirmSafety();
        $world->run();
        $world->licence->lapse();

        try {
            $world->stack->modes()->change(RunWorld::PROPERTY, PropertyMode::Approval, RunWorld::OWNER, new ModesConfig(minShadowRuns: 1));
            self::fail('a lapsed licence keeps the property in shadow mode');
        } catch (ModeRefused $refused) {
            self::assertContains(LicenceEnforcer::UNMET_CODE, array_column($refused->conditions, 'code'));
        }
        self::assertSame(PropertyMode::Shadow, $world->property()->mode);

        $world->licence->activate();
        self::assertSame(PropertyMode::Approval, $world->stack->modes()->change(RunWorld::PROPERTY, PropertyMode::Approval, RunWorld::OWNER, new ModesConfig(minShadowRuns: 1))->mode);
    }
}
