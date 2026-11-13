<?php

declare(strict_types=1);

namespace Ritmo\Tests\Integration\Write\Calendar;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Ritmo\Application\Write\Calendar\PmsWriteBatch;
use Ritmo\Application\Write\Calendar\StayWriteRound;
use Ritmo\Application\Write\Execute\StopReason;
use Ritmo\Application\Write\Journal\OpStatus;
use Ritmo\Domain\Model\RoomType;
use Ritmo\Domain\Plan\PlanItem;
use Ritmo\Domain\Plan\ValidatedPlan;
use Ritmo\Domain\Stay\Write\PmsChangeEntry;
use Ritmo\Domain\Stay\Write\PmsCheckContext;
use Ritmo\Domain\Stay\Write\RevertLedger;
use Ritmo\Domain\Stay\Write\StayWriteConfig;
use Ritmo\Domain\Stay\Write\StayWritePlan;
use Ritmo\Domain\Support\Days;
use Ritmo\Domain\Write\State\ChangeField;
use Ritmo\Domain\Write\State\NightFields;
use Ritmo\Tests\Integration\Write\Support\SimWorld;

/**
 * Stay writes against the simulator (in this process, behind the real HTTP guard, gate, journal
 * and executor; spec 06, 3.11): the paired pricing step goes first, the PMS gets only the fields
 * of its allow-list on the calendar route, a change whose pricing step is missing never leaves,
 * a change the pricing system already holds goes alone, a night changed since it was read is
 * skipped, the emergency switch stops the PMS block, and shadow sends nothing.
 */
final class StayWriteSimulatorTest extends TestCase
{
    /** The fields a PMS calendar body may carry (CalendarWriteBody, FieldLocks). */
    private const PMS_KEYS = ['roomId', 'from', 'to', 'minStay', 'maxStay', 'override', 'numAvail', 'channels'];
    private const NIGHT = '2027-03-15';

    private SimWorld $world;

    protected function setUp(): void
    {
        $this->world = new SimWorld();
    }

    public function testAPairedStayGoesToThePricingSystemFirstThenToThePms(): void
    {
        $plan = $this->world->validate([SimWorld::item('STD', self::NIGHT, null, null, 3)]);
        $batch = $this->batch($plan, [self::entry(3)->with(false, 'STD|' . self::NIGHT)]);
        self::assertCount(1, $batch->changes);

        $outcome = $this->world->write($plan, $batch->changes);

        self::assertNull($outcome->stopReason);
        self::assertSame(['stored', 'stored'], array_map(static fn ($r): string => $r->status->value, $outcome->results));
        self::assertSame(['POST /pricelabs/v1/listings/' . SimWorld::STD . '/overrides', 'POST /beds24/v2/inventory/rooms/calendar'], $this->world->apis->writeLines());
        self::assertSame(3, $this->world->apis->world()['pms']['902001'][self::NIGHT]['minStay']);
        $this->assertOnlyAllowedPmsFields();
    }

    public function testAPushedChangeWhosePricingItemWasRefusedNeverLeaves(): void
    {
        $plan = $this->world->validate([SimWorld::item('GDN', '2027-03-11', 600.0)]);
        $batch = $this->batch($plan, [self::entry(3)->with(false, 'STD|' . self::NIGHT)]);

        self::assertSame([], $batch->changes);
        self::assertSame('pms_unpaired', $batch->check->refused[0]['code']);
        $this->world->write($plan, $batch->changes);
        self::assertSame(['POST /pricelabs/v1/listings/' . SimWorld::GDN . '/overrides'], $this->world->apis->writeLines());
    }

    public function testWhenThePricingStepIsRefusedByTheSystemThePmsBlockIsNotSent(): void
    {
        $plan = $this->world->validate([SimWorld::item('STD', self::NIGHT, null, null, 3)]);
        $batch = $this->batch($plan, [self::entry(3)->with(false, 'STD|' . self::NIGHT)]);
        $this->world->apis->fault('POST', '/pricelabs/v1/listings/' . SimWorld::STD . '/overrides', 400);

        $outcome = $this->world->write($plan, $batch->changes);

        self::assertNotNull($outcome->stopReason);
        self::assertNotSame(OpStatus::Stored, $outcome->results[1]->status ?? OpStatus::Abandoned);
        self::assertSame(['POST /pricelabs/v1/listings/' . SimWorld::STD . '/overrides'], $this->world->apis->writeLines(), 'nothing reaches the PMS');
    }

    public function testAChangeThePricingSystemAlreadyHoldsGoesAlone(): void
    {
        $plan = $this->world->validate([SimWorld::item('GDN', '2027-03-11', 600.0)]);
        $batch = $this->batch($plan, [self::entry(3)->with(true, null)]);

        $outcome = $this->world->write($plan, $batch->changes);

        self::assertSame(['stored', 'stored'], array_map(static fn ($r): string => $r->status->value, $outcome->results));
        self::assertSame(3, $this->world->apis->world()['pms']['902001'][self::NIGHT]['minStay']);
        $this->assertOnlyAllowedPmsFields();
    }

    public function testANightChangedSinceItWasReadIsSkipped(): void
    {
        $plan = $this->world->validate([SimWorld::item('GDN', '2027-03-11', 600.0)]);
        $batch = $this->batch($plan, [self::entry(3, 5)->with(true, null)]);

        $outcome = $this->world->write($plan, $batch->changes);

        self::assertSame(OpStatus::Skipped, $outcome->results[1]->status);
        self::assertSame('changed_since_read', $outcome->results[1]->reason);
        self::assertArrayNotHasKey('902001', $this->world->apis->world()['pms'] ?? []);
    }

    public function testTheEmergencySwitchBetweenThePricingStepAndThePmsBlockStopsIt(): void
    {
        $plan = $this->world->validate([SimWorld::item('STD', self::NIGHT, null, null, 3)]);
        $batch = $this->batch($plan, [self::entry(3)->with(false, 'STD|' . self::NIGHT)]);
        $issued = $this->world->issue($plan, $batch->changes);
        $beats = 0;
        $switch = $this->world->switch;
        $outcome = $this->world->execute($issued, $plan, static function () use (&$beats, $switch): bool {
            if (++$beats === 2) {
                $switch->engage();
            }

            return true;
        });

        self::assertSame(StopReason::KillSwitch, $outcome->stopReason);
        self::assertSame(['POST /pricelabs/v1/listings/' . SimWorld::STD . '/overrides'], $this->world->apis->writeLines());
    }

    public function testShadowPlansTheSameBatchAndSendsNothing(): void
    {
        $plan = $this->world->validate([SimWorld::item('STD', self::NIGHT, null, null, 3)]);
        $batch = $this->batch($plan, [self::entry(3)->with(false, 'STD|' . self::NIGHT)]);

        self::assertCount(1, $batch->changes, 'shadow shows what would be written');
        self::assertSame([], $this->world->apis->writeLines(), 'zero POST and zero DELETE');
    }

    /**
     * @param list<PmsChangeEntry> $entries
     */
    private function batch(ValidatedPlan $plan, array $entries): PmsWriteBatch
    {
        $accepted = $targets = [];
        foreach ($plan->plan->items as $item) {
            $accepted[$item->key()] = true;
        }
        foreach ($entries as $entry) {
            $targets[$entry->identity()] = true;
        }
        $rooms = [];
        foreach (SimWorld::rooms() as $room) {
            $rooms[$room->code] = $room;
        }
        $context = new PmsCheckContext(
            $this->world->clock->today('UTC'),
            $this->world->clock->now(),
            $rooms,
            [],
            [],
            $targets,
            $accepted,
            [],
            new RevertLedger(),
            14,
            24,
            new StayWriteConfig(true, 60, 300, 2, false),
        );

        return (new StayWriteRound())->batch(new StayWritePlan($entries, $plan->plan->items, [], [], []), $context, $rooms);
    }

    private static function entry(int $minStay, int $before = 1): PmsChangeEntry
    {
        return new PmsChangeEntry('STD', Days::date(self::NIGHT), new NightFields([ChangeField::PMS_MIN_STAY => $minStay]), new NightFields([ChangeField::PMS_MIN_STAY => $before]), 'arrival_day', true);
    }

    private function assertOnlyAllowedPmsFields(): void
    {
        $pms = array_values(array_filter($this->world->apis->writeBodies(), static fn (array $w): bool => str_starts_with($w['path'], '/beds24/')));
        self::assertNotSame([], $pms);
        self::assertSame(['/beds24/v2/inventory/rooms/calendar'], array_values(array_unique(array_column($pms, 'path'))), 'no booking route');
        $json = json_encode($pms, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('"price', $json);
        foreach ($pms as $write) {
            $body = is_string($write['body'] ?? null) ? json_decode($write['body'], true) : ($write['body'] ?? []);
            foreach ((array) $body as $room) {
                foreach ($room['calendar'] ?? [$room] as $range) {
                    self::assertSame([], array_diff(array_keys((array) $range), self::PMS_KEYS), $json);
                }
            }
        }
    }
}
