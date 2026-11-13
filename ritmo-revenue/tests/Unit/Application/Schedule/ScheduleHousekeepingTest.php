<?php

declare(strict_types=1);

namespace Ritmo\Tests\Unit\Application\Schedule;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Ritmo\Application\Schedule\ScheduleHousekeeping;
use Ritmo\Domain\Operations\BookingVersion;
use Ritmo\Domain\Operations\MovementKind;
use Ritmo\Domain\Operations\ScheduleConfig;
use Ritmo\Domain\Operations\SlotPlanner;
use Ritmo\Domain\Operations\SlotStatus;
use Ritmo\Domain\Support\Days;
use Ritmo\Infrastructure\Schedule\BookingMovementRepository;
use Ritmo\Tests\Unit\Domain\Operations\MovementDetectorTest;

/**
 * The daily purge done by the first tick of each UTC day (operations spec 3.7 and 8.1).
 */
final class ScheduleHousekeepingTest extends TestCase
{
    public function testTheFirstTickOfADayPurgesOldSlotsAndDepartedBookingsOnce(): void
    {
        $world = new ScheduleWorld('2026-11-12T10:31:00Z');
        $movements = new BookingMovementRepository($world->db);
        $planner = new SlotPlanner(ScheduleConfig::fromArray([]), 'America/Sao_Paulo');
        $old = $planner->slotsOn(Days::date('2026-08-01'))[0];
        $oldDue = $planner->slotsOn(Days::date('2026-08-02'))[0];
        $recent = $planner->slotsOn(Days::date('2026-08-20'))[0];
        foreach ([$old, $recent] as $slot) {
            $world->slots->materialise('inn', $slot);
        }
        // A property the tick does not handle any more keeps its slot due.
        $world->slots->materialise('gone', $oldDue);
        foreach ([$old, $recent] as $slot) {
            $world->slots->close($world->slots->findByKey('inn', $slot)?->id ?? 0, SlotStatus::SkippedMissedDay, $slot->at);
        }
        $movements->record('inn', 'pms', [
            [BookingVersion::of(MovementDetectorTest::booking('1', '2026-11-01', '2026-11-03')), MovementKind::New],
            [BookingVersion::of(MovementDetectorTest::booking('2', '2026-11-02', '2026-11-04')), MovementKind::New],
            [BookingVersion::of(MovementDetectorTest::booking('3', '2026-11-20', '2026-11-22')), MovementKind::New],
        ], new DateTimeImmutable('2026-11-01T12:00:00Z'), null);

        $world->tick();

        self::assertNull($world->slots->findByKey('inn', $old), 'ended more than 90 days ago');
        self::assertNotNull($world->slots->findByKey('gone', $oldDue), 'a slot still due is never purged');
        self::assertNotNull($world->slots->findByKey('inn', $recent));
        self::assertSame(['2', '3'], array_map('strval', array_keys($movements->lastVersions('inn', 'pms', ['1', '2', '3']))), 'departed before today - 8 days (UTC) is forgotten');
        self::assertSame('2026-11-12', $world->store->getFresh(ScheduleHousekeeping::DAY_KEY));

        $world->clock->advance(300);
        $world->slots->materialise('inn', $old);
        $world->slots->close($world->slots->findByKey('inn', $old)?->id ?? 0, SlotStatus::SkippedMissedDay, $old->at);
        $world->tick();
        self::assertNotNull($world->slots->findByKey('inn', $old), 'once a day');

        $world->at('2026-11-13T00:01:00Z');
        $world->tick();
        self::assertNull($world->slots->findByKey('inn', $old));
        self::assertSame(['3'], array_map('strval', array_keys($movements->lastVersions('inn', 'pms', ['2', '3']))));
    }
}
