<?php

declare(strict_types=1);

namespace Ritmo\Tests\Unit\Domain\Operations;

use PHPUnit\Framework\TestCase;
use Ritmo\Domain\Model\BookingStatus;
use Ritmo\Domain\Model\InventoryNight;
use Ritmo\Domain\Operations\BookingVersion;
use Ritmo\Domain\Operations\DeferredWork;
use Ritmo\Domain\Operations\LightRoundFacts;
use Ritmo\Domain\Operations\LightRoundPlan;
use Ritmo\Domain\Operations\LightRoundPlanner;
use Ritmo\Domain\Operations\LightRunConfig;
use Ritmo\Domain\Operations\LightTrigger;
use Ritmo\Domain\Operations\Movement;
use Ritmo\Domain\Operations\MovementKind;
use Ritmo\Domain\Operations\SoldOutFlip;
use Ritmo\Domain\Operations\SoldOutFlips;
use Ritmo\Domain\Support\Days;

/**
 * The scope of a light run (operations spec 4.4 to 4.7; O13 to O18), today 2026-11-12.
 */
final class LightRoundPlannerTest extends TestCase
{
    private const TODAY = '2026-11-12';

    /**
     * @param list<Movement>                         $movements
     * @param list<SoldOutFlip>                      $flips
     * @param array<string, true>                    $soldOut
     * @param array<string, list<\DateTimeImmutable>> $manual
     * @param array<string, bool>                    $quota
     */
    private static function plan(array $movements = [], array $flips = [], array $soldOut = [], array $manual = [], int $overbooked = 0, array $quota = ['room-a' => true, 'room-b' => true]): LightRoundPlan
    {
        return (new LightRoundPlanner(new LightRunConfig()))->plan(new LightRoundFacts(Days::date(self::TODAY), 60, $movements, $flips, $soldOut, $manual, $overbooked, $quota));
    }

    private static function sale(string $ref, string $arrival, string $departure, string $room = 'room-a', BookingStatus $status = BookingStatus::Confirmed, MovementKind $kind = MovementKind::New): Movement
    {
        return new Movement($kind, MovementDetectorTest::booking($ref, $arrival, $departure, $status, $room));
    }

    public function testNothingMovedEndsTheRunWithTheShortReport(): void
    {
        $plan = self::plan();

        self::assertFalse($plan->continues());
        self::assertSame([], $plan->filter);
        self::assertSame([], $plan->affectedRooms);
    }

    public function testANewBookingPutsItsNightsOfTheHorizonInTheFilter(): void
    {
        $plan = self::plan([self::sale('1', '2026-11-20', '2026-11-22')]);

        self::assertSame([LightTrigger::Movement], $plan->triggers);
        self::assertSame(['room-a|2026-11-20', 'room-a|2026-11-21'], array_keys($plan->filter));
        self::assertSame(['room-a'], $plan->paceRooms);
    }

    public function testAModifiedBookingPutsTheOldAndTheNewNights(): void
    {
        $before = BookingVersion::of(MovementDetectorTest::booking('2', '2026-11-14', '2026-11-15', room: 'room-b'));
        $after = new Movement(MovementKind::Modified, MovementDetectorTest::booking('2', '2026-11-20', '2026-11-21'), $before);

        self::assertSame(['room-a|2026-11-20', 'room-b|2026-11-14'], array_keys(self::plan([$after])->filter));
    }

    public function testACancellationPutsItsNightsAndNightsOutsideTheHorizonStayOut(): void
    {
        $plan = self::plan([
            self::sale('3', '2026-11-10', '2026-11-14', status: BookingStatus::Cancelled, kind: MovementKind::Cancelled),
            self::sale('4', '2027-03-01', '2027-03-03'),
        ]);

        self::assertSame(['room-a|2026-11-12', 'room-a|2026-11-13'], array_keys($plan->filter), 'past nights and nights beyond 60 days are left out');
    }

    public function testABlockMovesNothingButAFlipNearTodayEntersWithoutABooking(): void
    {
        // L6: a block in D1 without a sale: the night is in the filter through its flip.
        $plan = self::plan(
            [self::sale('5', '2026-11-13', '2026-11-14', status: BookingStatus::Blocked)],
            [new SoldOutFlip('room-b', Days::date('2026-11-13'), true)],
            ['room-b|2026-11-13' => true],
        );

        self::assertSame(['room-b|2026-11-13'], array_keys($plan->filter));
        self::assertSame([LightTrigger::SoldOutFlip], $plan->triggers);
        self::assertSame(['room-b'], $plan->orphanRooms);
        self::assertCount(1, $plan->protectNow);
    }

    public function testAFlipBeyondTheProtectionDaysTriggersOnlyTheOrphanStays(): void
    {
        // L8: a cancellation reopens D5: orphan stays of D0..D7; the pace night itself comes from the movement.
        $plan = self::plan([], [new SoldOutFlip('room-a', Days::date('2026-11-17'), false)]);

        self::assertSame([], $plan->filter);
        self::assertSame(['room-a'], $plan->orphanRooms);
        self::assertSame(['2026-11-12', '2026-11-19'], [Days::key($plan->orphanWindow->first()), Days::key($plan->orphanWindow->last())]);
        self::assertTrue($plan->continues());
    }

    public function testARoomWithoutQuotaKeepsOnlyTheProtectionOfItsSoldOutNights(): void
    {
        // L9 and O17.
        $plan = self::plan(
            [self::sale('6', '2026-11-12', '2026-11-15')],
            soldOut: ['room-a|2026-11-12' => true, 'room-b|2026-11-30' => true],
            quota: ['room-a' => false],
        );

        self::assertSame(['room-a|2026-11-12'], array_keys($plan->filter));
        self::assertSame(['room-a'], $plan->safetyOnlyRooms);
        self::assertSame([], $plan->paceRooms);
        self::assertSame([
            'room-a|2026-11-13' => DeferredWork::PaceWithoutQuota,
            'room-a|2026-11-14' => DeferredWork::PaceWithoutQuota,
            'room-b|2026-11-30' => DeferredWork::ProtectionOutsideFilter,
        ], $plan->deferredKeys);
    }

    public function testAManualChangeContinuesTheRunButItsNightIsNotInTheFilter(): void
    {
        $plan = self::plan(manual: ['room-b' => [Days::date('2026-11-20')]]);

        self::assertSame([LightTrigger::ManualChange], $plan->triggers);
        self::assertSame([], $plan->filter);
        self::assertSame(['room-b'], $plan->affectedRooms);
    }

    public function testANewOverbookingContinuesTheRun(): void
    {
        self::assertSame([LightTrigger::Overbooking], self::plan(overbooked: 1)->triggers);
    }

    public function testListingSettingsAreAlwaysLeftToTheFullRun(): void
    {
        self::assertContains(DeferredWork::ListingSettings, LightRoundPlan::alwaysDeferred());
    }

    public function testFlipsCompareTheSoldOutStateOfTheTwoReads(): void
    {
        $now = ['room-a' => ['2026-11-12' => new InventoryNight(Days::date('2026-11-12'), 0), '2026-11-13' => new InventoryNight(Days::date('2026-11-13'), 2), '2026-11-14' => new InventoryNight(Days::date('2026-11-14'), 0), '2026-11-25' => new InventoryNight(Days::date('2026-11-25'), 0)]];
        $before = ['room-a' => ['2026-11-12' => new InventoryNight(Days::date('2026-11-12'), 1), '2026-11-13' => new InventoryNight(Days::date('2026-11-13'), 0), '2026-11-25' => new InventoryNight(Days::date('2026-11-25'), 3)]];

        $flips = SoldOutFlips::find($now, $before, Days::date(self::TODAY), 7);

        self::assertSame(['2026-11-12 sold out', '2026-11-13 reopened'], array_map(static fn (SoldOutFlip $f): string => Days::key($f->night) . ($f->soldOut ? ' sold out' : ' reopened'), $flips));
    }
}
