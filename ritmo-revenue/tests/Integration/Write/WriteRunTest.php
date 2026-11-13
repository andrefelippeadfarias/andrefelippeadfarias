<?php

declare(strict_types=1);

namespace Ritmo\Tests\Integration\Write;

use PHPUnit\Framework\TestCase;
use Ritmo\Application\Write\Journal\OpStatus;
use Ritmo\Application\Write\Schedule\CalendarChange;
use Ritmo\Contracts\WriteOperation;
use Ritmo\Domain\Plan\PlanDeletion;
use Ritmo\Domain\Support\Days;
use Ritmo\Tests\Integration\Write\Support\SimWorld;

/**
 * A write run against the simulator (in this process, behind the real guard and connectors):
 * every step once, in order, read back; a deletion and its rewrite together; the PMS calendar
 * without prices; the journal written ahead of every call.
 */
final class WriteRunTest extends TestCase
{
    private SimWorld $world;

    protected function setUp(): void
    {
        $this->world = new SimWorld();
    }

    public function testAHappyRunWritesEveryStepOnceAndReadsItBack(): void
    {
        $plan = $this->world->validate([
            SimWorld::item('STD', '2027-03-10', 450.0),
            SimWorld::item('GDN', '2027-03-11', 600.0, 900.0),
        ]);
        $night = Days::date('2027-03-12');
        $pms = [new CalendarChange('STD', '902001', $night, $night, minStay: 2, minStayBefore: 1)];

        $issued = $this->world->issue($plan, $pms);
        $outcome = $this->world->execute($issued, $plan);

        self::assertNull($outcome->stopReason);
        self::assertSame(['stored'], array_values(array_unique(array_map(static fn ($r): string => $r->status->value, $outcome->results))));
        self::assertSame(['POST /pricelabs/v1/listings/' . SimWorld::STD . '/overrides', 'POST /pricelabs/v1/listings/' . SimWorld::GDN . '/overrides', 'POST /beds24/v2/inventory/rooms/calendar'], $this->world->apis->writeLines());
        self::assertSame('-6', $this->world->stored(SimWorld::STD)['2027-03-10']['min_price']);
        self::assertSame(['min_price' => '-14', 'max_price' => '28'], array_intersect_key($this->world->stored(SimWorld::GDN)['2027-03-11'], ['min_price' => 1, 'max_price' => 1]));
        self::assertSame(2, $this->world->apis->world()['pms']['902001']['2027-03-12']['minStay']);
        self::assertFalse($this->world->gate->isLive($issued->token), 'the token ends with the run');

        $rows = $this->world->ops->ofRun(1);
        self::assertCount(3, $rows);
        self::assertSame(['stored', 'stored', 'stored'], array_column($rows, 'status'));
        self::assertSame([1, 1, 1], array_column($rows, 'attempts'));
        self::assertStringNotContainsString('sim-pricelabs-key', json_encode($rows, JSON_THROW_ON_ERROR), 'the journal never keeps a credential');
    }

    public function testADeletionAndItsRewriteGoTogetherAndAreReadBackAsOne(): void
    {
        $plan = $this->world->validate([SimWorld::item('STD', '2027-03-05', 500.0, 800.0)], [new PlanDeletion('STD', Days::date('2027-03-05'), 'free_ceiling')]);

        $outcome = $this->world->write($plan);

        self::assertNull($outcome->stopReason);
        $ops = array_map(static fn ($r) => [$r->step->operation, $r->status], $outcome->results);
        self::assertSame([[WriteOperation::OverridesDelete, OpStatus::Stored], [WriteOperation::OverridesWrite, OpStatus::Stored]], $ops);
        self::assertSame($outcome->results[0]->step->groupKey, $outcome->results[1]->step->groupKey);
        $rule = $this->world->stored(SimWorld::STD)['2027-03-05'];
        self::assertSame(['5', '66'], [$rule['min_price'], $rule['max_price']], 'floor rounded up, ceiling down');
        self::assertSame([], $outcome->brokenGroups);
    }

    public function testThePmsNeverReceivesAPriceNorABooking(): void
    {
        $plan = $this->world->validate([SimWorld::item('STD', '2027-03-10', 450.0)]);
        $night = Days::date('2027-03-14');
        $this->world->write($plan, [new CalendarChange('GDN', '902002', $night, $night, available: 1, channels: ['booking' => 0])]);

        $pms = array_values(array_filter($this->world->apis->writeBodies(), static fn (array $w): bool => str_starts_with($w['path'], '/beds24/')));
        self::assertSame(['/beds24/v2/inventory/rooms/calendar'], array_column($pms, 'path'), 'no booking route, only the calendar');
        self::assertStringNotContainsString('"price', json_encode($pms, JSON_THROW_ON_ERROR));
        self::assertSame(['maxBookings' => '0'], $this->world->apis->world()['pms']['902002']['2027-03-14']['channels']['booking']);
        self::assertSame(1, $this->world->apis->world()['pms']['902002']['2027-03-14']['numAvail']);
    }

    public function testAPmsValueThePricingSystemAlsoPushesWaitsForItsPricingStep(): void
    {
        $night = Days::date('2027-03-15');
        $pushed = new CalendarChange('STD', '902001', $night, $night, minStay: 3, alsoPricing: true);

        $alone = $this->world->write($this->world->validate([SimWorld::item('GDN', '2027-03-11', 600.0)]), [$pushed]);
        self::assertSame('pricing_first', $alone->results[1]->reason);
        self::assertSame(OpStatus::Skipped, $alone->results[1]->status);
        self::assertContains('pricing_first_skipped', $alone->alerts);

        $this->world->clock->advance(60);
        $together = $this->world->write($this->world->validate([SimWorld::item('STD', '2027-03-15', 450.0, null, 3)]), [$pushed]);
        self::assertSame(['stored', 'stored'], array_map(static fn ($r): string => $r->status->value, $together->results));
    }

    public function testAPmsNightChangedSinceItWasPlannedIsSkipped(): void
    {
        $night = Days::date('2027-03-16');
        $plan = $this->world->validate([SimWorld::item('STD', '2027-03-10', 450.0)]);
        $outcome = $this->world->write($plan, [
            new CalendarChange('STD', '902001', $night, $night, minStay: 2, minStayBefore: 3),
            new CalendarChange('GDN', '902002', $night, $night, minStay: 4, minStayBefore: 2),
        ]);

        self::assertSame(OpStatus::Stored, $outcome->results[1]->status, 'the change still valid goes');
        self::assertSame(['902002'], array_map('strval', array_keys($outcome->results[1]->step->nights)), 'the changed night left out');
        self::assertArrayNotHasKey('902001', $this->world->apis->world()['pms']);
    }
}
