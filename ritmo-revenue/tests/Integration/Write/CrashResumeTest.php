<?php

declare(strict_types=1);

namespace Ritmo\Tests\Integration\Write;

use PHPUnit\Framework\TestCase;
use Ritmo\Application\Write\Execute\StopReason;
use Ritmo\Application\Write\Gate\WriteRefused;
use Ritmo\Application\Write\Journal\OpStatus;
use Ritmo\Application\Write\Resume\BrokenGroup;
use Ritmo\Application\Write\Schedule\CalendarChange;
use Ritmo\Domain\Plan\PlanDeletion;
use Ritmo\Domain\Support\Days;
use Ritmo\Tests\Integration\Write\Support\SimulatedCrash;
use Ritmo\Tests\Integration\Write\Support\SimWorld;

/**
 * After a crash or a stop, the next run reads every unsettled step again and sends only what is
 * missing: never the old payload, never a step twice; a group stopped between its deletion and
 * its rewrite is repaired first.
 */
final class CrashResumeTest extends TestCase
{
    private SimWorld $world;

    protected function setUp(): void
    {
        $this->world = new SimWorld();
    }

    /**
     * @return array<string, int> "METHOD /path" => times received
     */
    private function writesReceived(): array
    {
        return array_count_values($this->world->apis->writeLines());
    }

    public function testACrashAfterTheSecondWriteResumesWithoutSendingAnythingTwice(): void
    {
        $plan = $this->world->validate([SimWorld::item('STD', '2027-03-10', 450.0), SimWorld::item('GDN', '2027-03-11', 600.0)]);
        $night = Days::date('2027-03-12');
        $pms = [new CalendarChange('STD', '902001', $night, $night, minStay: 2, minStayBefore: 1)];
        $this->world->apis->crashAfterWrites(2);
        try {
            $this->world->write($plan, $pms);
            self::fail('the process should have died');
        } catch (SimulatedCrash) {
            self::assertSame(['accepted', 'sending', 'planned'], array_column($this->world->ops->ofRun(1), 'status'), 'the journal was written ahead');
        }

        $this->world->clock->advance(300);
        $report = $this->world->resume();
        self::assertSame([OpStatus::Stored, OpStatus::Stored, OpStatus::Abandoned], array_values($report->rows), 'both calls applied, the lost answer included; the third never left');

        $fresh = $this->world->validate([$this->world->freshItem('STD', '2027-03-10', 450.0), $this->world->freshItem('GDN', '2027-03-11', 600.0)]);
        $outcome = $this->world->write($fresh, $pms);

        self::assertNull($outcome->stopReason);
        self::assertSame(['pms 1/1'], array_map(static fn ($r): string => $r->step->label, $outcome->results), 'only what is missing');
        self::assertSame([
            'POST /pricelabs/v1/listings/' . SimWorld::STD . '/overrides' => 1,
            'POST /pricelabs/v1/listings/' . SimWorld::GDN . '/overrides' => 1,
            'POST /beds24/v2/inventory/rooms/calendar' => 1,
        ], $this->writesReceived());
    }

    public function testAResumeOverAFullyAppliedJournalWritesNothing(): void
    {
        $plan = $this->world->validate([SimWorld::item('STD', '2027-03-10', 450.0)]);
        $this->world->apis->timeout('POST', '/pricelabs/v1/listings/' . SimWorld::STD . '/overrides', true);
        self::assertSame(StopReason::Unknown, $this->world->write($plan)->stopReason);

        $this->world->clock->advance(300);
        $this->world->resume();
        $fresh = $this->world->validate([$this->world->freshItem('STD', '2027-03-10', 450.0)]);

        try {
            $this->world->issue($fresh);
            self::fail('a plan with nothing missing got a token');
        } catch (WriteRefused $refused) {
            self::assertSame('nothing_to_write', $refused->reason);
        }
        self::assertSame(['POST /pricelabs/v1/listings/' . SimWorld::STD . '/overrides' => 1], $this->writesReceived());
    }

    public function testACrashBetweenADeletionAndItsRewriteIsRepairedFirst(): void
    {
        $plan = $this->world->validate(
            [SimWorld::item('STD', '2027-03-05', 500.0, 800.0), SimWorld::item('GDN', '2027-03-11', 600.0)],
            [new PlanDeletion('STD', Days::date('2027-03-05'), 'free_ceiling')]
        );
        $this->world->apis->crashAfterWrites(1);
        try {
            $this->world->write($plan);
            self::fail('the process should have died');
        } catch (SimulatedCrash) {
            self::assertArrayNotHasKey('2027-03-05', $this->world->stored(SimWorld::STD), 'the night is left without its rule');
        }

        $this->world->clock->advance(300);
        $report = $this->world->resume();
        self::assertCount(1, $report->broken);
        $group = $report->broken[0];
        self::assertSame('STD', $group->roomCode);

        $repair = $this->world->validate($group->repairItems(SimWorld::rooms()[0], '2027-03-03'));
        self::assertSame(BrokenGroup::REASON, $repair->plan->items[0]->reasonCode);
        $outcome = $this->world->write($repair);

        self::assertNull($outcome->stopReason);
        $rule = $this->world->stored(SimWorld::STD)['2027-03-05'];
        self::assertSame(['5', '66'], [$rule['min_price'], $rule['max_price']], 'the rewrite the crash cut off');
        self::assertSame(1, $this->writesReceived()['DELETE /pricelabs/v1/listings/' . SimWorld::STD . '/overrides'], 'the deletion is never sent again');
    }

    public function testTheEmergencySwitchBetweenTheTwoHalvesOfAGroupRaisesTheCriticalAlert(): void
    {
        $plan = $this->world->validate([SimWorld::item('STD', '2027-03-05', 500.0, 800.0)], [new PlanDeletion('STD', Days::date('2027-03-05'), 'free_ceiling')]);
        $issued = $this->world->issue($plan);
        $switch = $this->world->switch;
        $beats = 0;
        $outcome = $this->world->execute($issued, $plan, static function () use (&$beats, $switch): bool {
            if (++$beats === 2) {
                $switch->engage();
            }

            return true;
        });

        self::assertSame(StopReason::KillSwitch, $outcome->stopReason);
        self::assertSame(['stored', 'abandoned'], array_map(static fn ($r): string => $r->status->value, $outcome->results));
        self::assertSame([$issued->steps[0]->groupKey], $outcome->brokenGroups);
        self::assertContains('night_without_rule', $outcome->alerts);

        $switch->release();
        $this->world->clock->advance(60);
        $report = $this->world->resume();
        self::assertCount(1, $report->broken, 'the resume after the release repairs it first');
    }
}
