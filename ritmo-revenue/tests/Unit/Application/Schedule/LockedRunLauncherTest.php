<?php

declare(strict_types=1);

namespace Ritmo\Tests\Unit\Application\Schedule;

use OutOfBoundsException;
use PHPUnit\Framework\TestCase;
use Ritmo\Application\Schedule\FailureKind;
use Ritmo\Application\Schedule\LaunchStatus;
use Ritmo\Application\Schedule\LockedRunLauncher;
use Ritmo\Application\Schedule\RunNowService;
use Ritmo\Application\Schedule\TickReport;
use Ritmo\Domain\Model\Property;
use Ritmo\Domain\Model\PropertyMode;
use Ritmo\Domain\Operations\RunKind;
use Ritmo\Infrastructure\Db\Repository\PropertyRepository;
use Ritmo\Infrastructure\Db\Repository\RunRepository;

/**
 * The single run lock of a property over the real lock table and a movable clock (operations
 * spec 3.4, O1; scheduler tests S7, S8, S9, S11, S18).
 */
final class LockedRunLauncherTest extends TestCase
{
    private ScheduleWorld $world;

    private ScriptedExecutor $executor;

    private LockedRunLauncher $launcher;

    protected function setUp(): void
    {
        $this->world = new ScheduleWorld('2026-11-12T10:31:00Z');
        $this->executor = new ScriptedExecutor($this->world->runs, $this->world->clock);
        $this->launcher = new LockedRunLauncher($this->world->runs, $this->world->locks, $this->executor, $this->world->clock, $this->world->logger);
        $this->world->runLauncher = $this->launcher;
    }

    public function testARunStartsUnderThePropertyLockForThePropertysDayAndGivesTheLockBack(): void
    {
        // 23:30 in the property's timezone is already the next day in UTC.
        $this->world->at('2026-11-13T02:30:00Z');
        $result = $this->launcher->start($this->inn(), RunKind::Full, false, 20);

        self::assertSame(LaunchStatus::Completed, $result->status);
        [$call, $kind, $forced, $today, $deadline] = $this->executor->calls[0];
        self::assertSame(['start', RunKind::Full, false, '2026-11-12'], [$call, $kind, $forced, $today]);
        self::assertSame('2026-11-13T02:30:20+00:00', $deadline->format(DATE_ATOM));
        self::assertSame([true], $this->executor->heartbeats);
        self::assertFalse($this->world->locks->isLocked(LockedRunLauncher::lockKey('inn')));
    }

    public function testS7AndS18WhileARunWorksNoOtherRunOfThePropertyStartsAndTheTickWaits(): void
    {
        $this->executor->during = function (): void {
            self::assertTrue($this->world->locks->isLocked(LockedRunLauncher::lockKey('inn')));
            self::assertSame(LaunchStatus::Busy, $this->launcher->start($this->inn(), RunKind::ApprovedWrite, false, 20)->status);
            self::assertSame(LaunchStatus::Busy, $this->launcher->continueOpen($this->inn(), 20)?->status);
            self::assertSame(LaunchStatus::Busy, $this->launcher->stopOpen($this->inn(), 'paused')?->status);
            self::assertSame(['inn' => TickReport::WAITING], $this->world->tick()->properties);
            self::assertSame('due', $this->world->slotStatuses()['2026-11-12T07:30|full']);
        };

        $result = $this->runNow()->run('inn');

        self::assertSame(LaunchStatus::Completed, $result->status);
        self::assertSame(['start:manual'], $this->executor->trail(), 'one run, never two');
        $this->world->at('2026-11-12T10:36:00Z');
        $this->world->tick();
        self::assertSame('skipped_covered', $this->world->slotStatuses()['2026-11-12T07:30|full'], 'the manual run read what the slot would read');
    }

    public function testARunInProgressIsContinuedAndNeverDoubled(): void
    {
        $this->executor->next = ['running'];
        $first = $this->launcher->start($this->inn(), RunKind::Full, false, 20);

        self::assertSame(LaunchStatus::Running, $first->status);
        self::assertSame(LaunchStatus::Busy, $this->launcher->start($this->inn(), RunKind::Light, false, 20)->status);
        self::assertSame(LaunchStatus::Completed, $this->launcher->continueOpen($this->inn(), 20)?->status);
        self::assertSame(['start:full', 'resume:' . $first->runId], $this->executor->trail());
        self::assertNull($this->launcher->continueOpen($this->inn(), 20), 'nothing left to continue');
        self::assertFalse($this->world->locks->isLocked(LockedRunLauncher::lockKey('inn')));
    }

    public function testAnOpenRunOfAnyKindExcludesANewOneTheShadowRunOfTheReadOnlyMilestoneIncluded(): void
    {
        $shadow = $this->world->runs->start('inn', 'shadow', PropertyMode::Shadow, null, $this->world->clock->now(), $this->world->clock->today('America/Sao_Paulo'));

        self::assertSame(LaunchStatus::Busy, $this->launcher->start($this->inn(), RunKind::Full, false, 20)->status);
        self::assertSame(LaunchStatus::Completed, $this->launcher->continueOpen($this->inn(), 20)?->status);
        self::assertSame(['resume:' . $shadow], $this->executor->trail());
    }

    public function testS8ALockWhoseHolderDiedExpiresAndTheOpenRunResumesFromItsNextStage(): void
    {
        $this->executor->next = ['running'];
        $this->world->tick();
        $runId = $this->world->slot('2026-11-12T07:30|full')?->runId;
        self::assertSame('running', $this->world->slotStatuses()['2026-11-12T07:30|full']);

        // A request working on the run dies with the lock held.
        self::assertNotNull($this->world->locks->acquire(LockedRunLauncher::lockKey('inn'), LockedRunLauncher::LOCK_TTL_SECONDS));
        $this->world->at('2026-11-12T10:36:00Z');
        self::assertSame(['inn' => TickReport::WAITING], $this->world->tick()->properties);
        $this->world->at('2026-11-12T10:41:01Z');
        self::assertSame(['inn' => TickReport::CONTINUED], $this->world->tick()->properties);

        self::assertSame(['start:full', 'resume:' . $runId], $this->executor->trail(), 'resumed, never restarted');
        self::assertSame('completed', $this->world->slotStatuses()['2026-11-12T07:30|full']);
    }

    public function testS9ARunOfAnEarlierDayIsClosedAsFailedAndTheNewDayStartsAfresh(): void
    {
        $this->world->at('2026-11-13T02:58:00Z');
        $this->executor->next = ['running'];
        $open = $this->launcher->start($this->inn(), RunKind::Light, false, 20);

        $this->world->at('2026-11-13T03:03:00Z');
        $closed = $this->launcher->continueOpen($this->inn(), 20);

        self::assertSame([LaunchStatus::Failed, FailureKind::DayChanged, $open->runId], [$closed?->status, $closed?->failure, $closed?->runId]);
        self::assertSame(RunRepository::FAILED, $this->world->runs->find((int) $open->runId)['status'] ?? null);
        self::assertSame(['start:light'], $this->executor->trail(), 'never resumed into the new day');

        $this->executor->next = ['running'];
        $this->world->at('2026-11-13T02:58:00Z');
        $this->launcher->start($this->inn(), RunKind::Light, false, 20);
        $this->world->at('2026-11-13T03:03:00Z');
        $now = $this->runNow()->run('inn');
        self::assertSame(LaunchStatus::Completed, $now->status, 'a person who runs now gets a run of the new day');
        self::assertSame('2026-11-13', $this->executor->calls[2][3]);
    }

    public function testAPausedRunStopsAtItsStageBoundary(): void
    {
        $this->executor->next = ['running'];
        $open = $this->launcher->start($this->inn(), RunKind::Full, false, 20);

        $stopped = $this->launcher->stopOpen($this->inn(), 'The property is paused.');

        self::assertSame(LaunchStatus::Stopped, $stopped?->status);
        $run = $this->world->runs->find((int) $open->runId);
        self::assertSame([RunRepository::STOPPED, 'The property is paused.'], [$run['status'] ?? null, $run['error'] ?? null]);
        self::assertNull($this->launcher->stopOpen($this->inn(), 'again'));
    }

    public function testALostLockMakesTheHeartbeatSayStopAndTheNewHolderKeepsIt(): void
    {
        $this->executor->during = function (): void {
            $this->world->clock->advance(LockedRunLauncher::LOCK_TTL_SECONDS + 1);
            self::assertNotNull($this->world->locks->acquire(LockedRunLauncher::lockKey('inn'), 60));
        };

        $this->launcher->start($this->inn(), RunKind::Full, false, 20);

        self::assertSame([false], $this->executor->heartbeats);
        self::assertTrue($this->world->locks->isLocked(LockedRunLauncher::lockKey('inn')), 'giving back a lost lock frees nothing');
    }

    public function testS11APausedPropertyStillRunsNowAsAManualRun(): void
    {
        $this->world->states->pause('inn', null, 1, 'Owner away');
        $this->world->tick();
        self::assertSame('skipped_paused', $this->world->slotStatuses()['2026-11-12T07:30|full']);

        $result = $this->runNow()->run('inn');

        self::assertSame(LaunchStatus::Completed, $result->status);
        self::assertSame(['start:manual'], $this->executor->trail());
    }

    public function testUnderTheEmergencySwitchRunNowIsAForcedShadowRun(): void
    {
        $this->world->switch->engage();

        $this->runNow()->run('inn');

        self::assertTrue($this->executor->calls[0][2]);
        $this->expectException(OutOfBoundsException::class);
        $this->runNow()->run('nowhere');
    }

    private function runNow(): RunNowService
    {
        return new RunNowService(new PropertyRepository($this->world->db, $this->world->clock), $this->launcher, $this->world->switch);
    }

    private function inn(): Property
    {
        return (new PropertyRepository($this->world->db, $this->world->clock))->find('inn') ?? throw new OutOfBoundsException('inn');
    }
}
