<?php

declare(strict_types=1);

namespace Ritmo\Tests\Unit\Domain\Calendar\Stay;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Ritmo\Domain\Calendar\Stay\ArrivalDepartureChange;
use Ritmo\Domain\Calendar\Stay\ChangeTarget;
use Ritmo\Domain\Calendar\Stay\OrphanKind;
use Ritmo\Domain\Calendar\Stay\RoomAudit;
use Ritmo\Domain\Calendar\Stay\StayChange;
use Ritmo\Domain\Calendar\Stay\StayReason;
use Ritmo\Domain\Calendar\Stay\StaySyncCapability;
use Ritmo\Domain\Model\Restriction;
use Ritmo\Domain\Support\Days;

/**
 * 1 March 2027 is a Monday; Saturdays 6 and 13 March.
 */
final class InventoryAuditTest extends TestCase
{
    private const TODAY = '2027-03-01';

    public function testTheNeutralRulesChangeNothingAndOnlyReportRestrictions(): void
    {
        $audit = $this->audit([], ['Deluxe' => StaySample::nights([
            '2027-03-05' => [2, 3],
            '2027-03-06' => [0, 2, Restriction::NoCheckinCheckout],
            '2027-03-07' => [1, 1, Restriction::Blackout],
        ])])[0];

        self::assertSame([[], [], [], [], []], [$audit->arrivalChanges, $audit->closedDayStayBelow, $audit->orphanStays, $audit->packageStays, $audit->arrivalStays]);
        self::assertSame([Restriction::NoCheckinCheckout, Restriction::Blackout], array_map(static fn ($n) => $n->restriction, $audit->otherRestricted));
    }

    public function testABlackoutIsNeverChangedEvenOnAClosedDay(): void
    {
        // Divergence D1 (docs/STAY.md): the reference engine overwrites a blacked-out Saturday with the closure
        $audit = $this->audit(['closed_days' => [StaySample::SATURDAY], 'open_days' => [['weekday' => 4, 'rooms' => ['Deluxe']]]], ['Deluxe' => StaySample::nights([
            '2027-03-05' => [2, 1, Restriction::Blackout],
            '2027-03-06' => [2, 2, Restriction::Blackout],
            '2027-03-13' => [2, 2, Restriction::None],
        ])])[0];

        self::assertSame(['2027-03-13'], $this->nights($audit->arrivalChanges));
        self::assertSame(Restriction::NoCheckinCheckout, $audit->arrivalChanges[0]->after);
        self::assertSame(StayReason::ClosedDay, $audit->arrivalChanges[0]->reason);
        self::assertSame([Restriction::Blackout], array_map(static fn ($n) => $n->restriction, $audit->otherRestricted), 'the open day keeps the blackout and reports it');
    }

    public function testAClosedDayAppliesToItsRoomsAndItsOwnStayIsReported(): void
    {
        $saturday = ['weekday' => 5, 'restriction' => 'no_checkin', 'min_stay' => 2, 'except_rooms' => ['Cottage']];
        $inventory = [
            'Deluxe' => StaySample::nights(['2027-03-06' => [2, 1, Restriction::None]]),
            'Cottage' => StaySample::nights(['2027-03-06' => [1, 1, Restriction::None]]),
        ];

        [$cottage, $deluxe] = $this->audit(['closed_days' => [$saturday], 'pricing_sync' => ['arrival_departure_rooms' => ['Deluxe']]], $inventory);

        self::assertSame('Cottage', $cottage->roomCode, 'rooms sorted by code');
        self::assertSame([], $cottage->arrivalChanges);
        self::assertSame(Restriction::NoCheckin, $deluxe->arrivalChanges[0]->after);
        self::assertTrue($deluxe->arrivalChanges[0]->alsoPricing);
        self::assertSame(['2027-03-06'], array_map(Days::key(...), $deluxe->closedDayStayBelow));
    }

    public function testTheOrphanSideOfAClosedDay(): void
    {
        $inventory = [
            'Deluxe' => StaySample::nights(['2027-03-05' => [2, 2], '2027-03-06' => [0, 2, Restriction::NoCheckinCheckout]]),
            'Standard' => StaySample::nights(['2027-03-05' => [0, 2], '2027-03-06' => [3, 2, Restriction::NoCheckinCheckout]]),
        ];

        [$deluxe, $standard] = $this->audit(['closed_days' => [StaySample::SATURDAY]], $inventory);

        self::assertSame([Restriction::NoCheckin, StayReason::OrphanSideClosedDaySoldOut], [$deluxe->arrivalChanges[0]->after, $deluxe->arrivalChanges[0]->reason]);
        self::assertSame(['2027-03-05'], $this->nights($deluxe->orphanStays), 'the free night before sells alone (no arrival rule in force)');
        self::assertSame([ChangeTarget::Pricing, OrphanKind::NextNight, 1], [$deluxe->orphanStays[0]->target, $deluxe->orphanStays[0]->orphan, $deluxe->orphanStays[0]->minStay]);
        self::assertSame([Restriction::NoCheckout, StayReason::OrphanSidePreviousSoldOut], [$standard->arrivalChanges[0]->after, $standard->arrivalChanges[0]->reason]);
        self::assertNull($standard->arrivalChanges[0]->proof);
        self::assertSame([ChangeTarget::Pricing, OrphanKind::ClosedDay], [$standard->orphanStays[0]->target, $standard->orphanStays[0]->orphan], 'a future free closed day goes to the pricing system');
        self::assertSame([], $standard->closedDayStayBelow, 'the free side is not a stay below the rule');
    }

    public function testTodaysClosedDayAfterAPastNightOpensWithAProofForThePms(): void
    {
        $readAt = new DateTimeImmutable('2027-03-06T10:00:00+00:00');
        $audit = StaySample::audit(['closed_days' => [StaySample::SATURDAY], 'pricing_sync' => ['min_stay_rooms' => ['*']]])
            ->audit(['Deluxe' => StaySample::nights(['2027-03-06' => [2, 2, Restriction::NoCheckinCheckout]])], Days::date('2027-03-06'), Days::date('2027-03-20'), Days::date('2027-03-06'), $readAt)[0];

        $change = $audit->arrivalChanges[0];
        self::assertSame([Restriction::NoCheckout, StayReason::OrphanSidePreviousPast, OrphanKind::ClosedDay], [$change->after, $change->reason, $change->orphan]);
        self::assertSame(['source' => 'pms', 'neighbour' => 'previous', 'units' => 4, 'night_available' => 2, 'read_at' => '2027-03-06T10:00:00+00:00', 'previous_past' => '2027-03-05'], $change->proof?->toArray());
        self::assertSame([ChangeTarget::Pms, 1, 2, true], [$audit->orphanStays[0]->target, $audit->orphanStays[0]->minStay, $audit->orphanStays[0]->minStayBefore, $audit->orphanStays[0]->alsoPricing]);
        self::assertSame($audit->orphanStays, $audit->pmsOrphanStays());
    }

    public function testAnOpenDayClearsArrivalAndDepartureRestrictions(): void
    {
        $audit = $this->audit(['open_days' => [['weekday' => 4, 'rooms' => ['Cottage']]]], ['Cottage' => StaySample::nights([
            '2027-03-05' => [1, 1, Restriction::NoCheckout],
            '2027-03-12' => [1, 1, Restriction::None],
        ])])[0];

        self::assertSame([[Restriction::NoCheckout, Restriction::None, StayReason::OpenDay]], array_map(static fn (ArrivalDepartureChange $c): array => [$c->before, $c->after, $c->reason], $audit->arrivalChanges));
        self::assertFalse($audit->arrivalChanges[0]->alsoPricing);
    }

    public function testAPackageNightTakesItsStayAndRestrictionAndNothingElse(): void
    {
        $stay = ['arrival_rule' => StaySample::ARRIVAL, 'closed_days' => [StaySample::SATURDAY], 'pricing_sync' => ['min_stay_rooms' => ['*'], 'max_stay_rooms' => ['*']],
                 'packages' => [['name' => 'P', 'nights' => [['date' => '2027-03-06', 'min_stay' => 3, 'max_stay' => 3, 'restriction' => 'no_checkout'], ['date' => '2027-03-08', 'max_stay' => 4]]]]];
        $inventory = ['Deluxe' => StaySample::nights([
            '2027-03-06' => [2, 2, Restriction::NoCheckinCheckout, 7],
            '2027-03-08' => [2, 1, Restriction::None, null],
        ])];

        $audit = $this->audit($stay, $inventory)[0];
        [$saturday, $monday] = $audit->packageStays;
        self::assertSame([3, 2, 3, 7, true], [$saturday->minStay, $saturday->minStayBefore, $saturday->maxStay, $saturday->maxStayBefore, $saturday->alsoPricing]);
        self::assertSame([null, null, 4, null], [$monday->minStay, $monday->minStayBefore, $monday->maxStay, $monday->maxStayBefore], 'no maximum read is null');
        self::assertSame([Restriction::NoCheckout, StayReason::Package], [$audit->arrivalChanges[0]->after, $audit->arrivalChanges[0]->reason]);
        self::assertSame([], $audit->arrivalStays, 'a package night keeps its own stay, even with only a maximum');

        $withoutSync = StaySample::audit($stay, StaySyncCapability::none())->audit($inventory, Days::date(self::TODAY), Days::date('2027-03-20'), Days::date(self::TODAY), null)[0];
        self::assertFalse($withoutSync->packageStays[0]->alsoPricing, 'a connector that cannot carry the stay asks nothing of the pricing system');
    }

    public function testTheArrivalRuleRaisesAndLowersTheStay(): void
    {
        $audit = $this->audit(['arrival_rule' => StaySample::ARRIVAL, 'orphan' => ['next_night' => true], 'pricing_sync' => ['min_stay_rooms' => ['Deluxe']]], ['Deluxe' => StaySample::nights([
            '2027-03-01' => [1, 1],
            '2027-03-02' => [1, 3],
            '2027-03-03' => [1, 2],
            '2027-03-04' => [0, 1],
            '2027-03-08' => [0, 1],
            '2027-03-10' => [2, 1],
            '2027-03-11' => [0, 1],
        ])])[0];

        self::assertSame([
            ['2027-03-01', 1, 2, StayReason::ArrivalDay, null, true],
            ['2027-03-02', 3, 1, StayReason::ArrivalDay, null, true],
            ['2027-03-03', 2, 1, StayReason::OrphanNight, OrphanKind::NextNight, true],
            ['2027-03-08', 1, 2, StayReason::ArrivalDay, null, true],
        ], $this->stays($audit->arrivalStays), 'a sold-out night follows the rule too; an orphan already at one night stays');
    }

    public function testAnIsolatedFreeNightKeepsItsOneNightStay(): void
    {
        $audit = $this->audit(['arrival_rule' => StaySample::ARRIVAL], ['Deluxe' => StaySample::nights([
            '2027-03-14' => [0, 1],
            '2027-03-15' => [1, 1],
            '2027-03-16' => [0, 1],
            '2027-03-22' => [1, 1],
        ])])[0];

        self::assertSame([['2027-03-22', 1, 2, StayReason::ArrivalDay, null, false]], $this->stays($audit->arrivalStays));
    }

    /**
     * @param list<StayChange> $changes
     *
     * @return list<array{string, ?int, ?int, StayReason, ?OrphanKind, bool}>
     */
    private function stays(array $changes): array
    {
        return array_map(static fn (StayChange $c): array => [Days::key($c->night), $c->minStayBefore, $c->minStay, $c->reason, $c->orphan, $c->alsoPricing], $changes);
    }

    /**
     * @param array<string, mixed>                                                      $stay
     * @param array<string, array<string, \Ritmo\Domain\Model\InventoryNight>>          $inventory
     *
     * @return list<RoomAudit>
     */
    private function audit(array $stay, array $inventory): array
    {
        return StaySample::audit($stay)->audit($inventory, Days::date(self::TODAY), Days::date('2027-03-31'), Days::date(self::TODAY), null);
    }

    /**
     * @param list<StayChange|ArrivalDepartureChange> $changes
     *
     * @return list<string>
     */
    private function nights(array $changes): array
    {
        return array_map(static fn ($c): string => Days::key($c->night), $changes);
    }
}
