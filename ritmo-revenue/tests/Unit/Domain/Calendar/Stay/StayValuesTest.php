<?php

declare(strict_types=1);

namespace Ritmo\Tests\Unit\Domain\Calendar\Stay;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Ritmo\Domain\Calendar\Stay\ArrivalDepartureChange;
use Ritmo\Domain\Calendar\Stay\ChangeTarget;
use Ritmo\Domain\Calendar\Stay\Occupancy;
use Ritmo\Domain\Calendar\Stay\OrphanProof;
use Ritmo\Domain\Calendar\Stay\PricingStayNight;
use Ritmo\Domain\Calendar\Stay\PricingSync;
use Ritmo\Domain\Calendar\Stay\StayChange;
use Ritmo\Domain\Calendar\Stay\StayFinding;
use Ritmo\Domain\Calendar\Stay\StayReason;
use Ritmo\Domain\Calendar\Stay\StaySyncCapability;
use Ritmo\Domain\Calendar\Stay\StaySyncField;
use Ritmo\Domain\Model\PriceNight;
use Ritmo\Domain\Model\Restriction;
use Ritmo\Domain\Support\Days;

/**
 * The value objects of the stay module: what they accept and refuse.
 */
final class StayValuesTest extends TestCase
{
    public function testAStayChangeSetsAStayOfAtLeastOneNight(): void
    {
        $change = new StayChange('Deluxe', Days::date('2027-03-01'), ChangeTarget::Pms, StayReason::ArrivalDay, minStay: 2, minStayBefore: 1);
        self::assertSame('stay|Deluxe|2027-03-01|2|1|', $change->identity());

        $this->expectException(InvalidArgumentException::class);
        new StayChange('Deluxe', Days::date('2027-03-01'), ChangeTarget::Pms, StayReason::ArrivalDay);
    }

    public function testAStayOfZeroNightsIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new StayChange('Deluxe', Days::date('2027-03-01'), ChangeTarget::Pms, StayReason::Package, maxStay: 0);
    }

    public function testAnArrivalChangeNeverTouchesABlackout(): void
    {
        $this->expectExceptionMessage('A blackout is availability, not an arrival/departure rule.');
        ArrivalDepartureChange::forPms('Deluxe', Days::date('2027-03-06'), StayReason::ClosedDay, Restriction::Blackout, Restriction::NoCheckinCheckout);
    }

    public function testAnArrivalChangeChangesSomething(): void
    {
        $this->expectException(InvalidArgumentException::class);
        ArrivalDepartureChange::forPms('Deluxe', Days::date('2027-03-06'), StayReason::ClosedDay, Restriction::NoCheckin, Restriction::NoCheckin);
    }

    public function testAMaskChangeIsForThePricingSystemWithValidMasks(): void
    {
        $change = ArrivalDepartureChange::forPricing('Deluxe', Days::date('2027-03-05'), StayReason::OpenDay, '1111111', '1111111');
        self::assertSame([ChangeTarget::Pricing, null, null, false], [$change->target, $change->before, $change->after, $change->alsoPricing]);

        $this->expectException(InvalidArgumentException::class);
        ArrivalDepartureChange::forPricing('Deluxe', Days::date('2027-03-05'), StayReason::OpenDay, '111111', '1111111');
    }

    public function testAProofNamesItsSourceAndNeighbour(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new OrphanProof('channel', OrphanProof::NEXT);
    }

    public function testAPricingNightIsValidatedAndBuiltFromThePricingConnectorsNight(): void
    {
        $night = PricingStayNight::fromPriceNight(new PriceNight(Days::date('2027-03-01'), 500.0, null, null, null, 3, 3), '1111111', '1111011', false);
        self::assertSame([1, 3, 3, true, false, '1111011'], [$night->minStay, $night->sold, $night->total, $night->isSoldOut(), $night->masksEnabled, $night->checkoutMask]);

        $this->expectException(InvalidArgumentException::class);
        new PricingStayNight(Days::date('2027-03-01'), 1, 0, 1, 'open');
    }

    public function testOccupancyFromThePmsTreatsMissingNightsAsNeitherFreeNorSoldOut(): void
    {
        $occupancy = Occupancy::fromInventory(StaySample::nights(['2027-03-01' => [1], '2027-03-02' => [0], '2027-03-03' => [-1]]), 4, null);

        self::assertSame([true, false, false, false], array_map(static fn (string $d): bool => $occupancy->free(Days::date($d)), ['2027-03-01', '2027-03-02', '2027-03-03', '2027-03-04']));
        self::assertSame([false, true, true, false], array_map(static fn (string $d): bool => $occupancy->soldOut(Days::date($d)), ['2027-03-01', '2027-03-02', '2027-03-03', '2027-03-04']));
        self::assertSame(['source' => 'pms', 'neighbour' => 'next', 'units' => 4, 'night_available' => 0, 'neighbour_available' => -1], $occupancy->proof(Days::date('2027-03-02'), OrphanProof::NEXT)->toArray());
        self::assertSame(['source' => 'pms', 'neighbour' => 'previous', 'units' => 4, 'neighbour_available' => -1], $occupancy->proofPreviousPast(Days::date('2027-03-04'))->toArray(), 'a sold-out night before needs no date');
        self::assertSame(['source' => 'pms', 'neighbour' => 'previous', 'units' => 4, 'night_available' => 0, 'neighbour_available' => 1, 'previous_past' => '2027-03-01'], $occupancy->proofPreviousPast(Days::date('2027-03-02'))->toArray());
    }

    public function testOccupancyFromThePricingCalendarCountsAMissingNightAsNothingSold(): void
    {
        $calendar = ['2027-03-01' => new PricingStayNight(Days::date('2027-03-01'), 1, 2, 2)];
        $occupancy = Occupancy::fromPricing($calendar, 2);

        self::assertTrue($occupancy->soldOut(Days::date('2027-03-01')));
        self::assertFalse($occupancy->free(Days::date('2027-03-02')));
        self::assertSame(['source' => 'pricing', 'neighbour' => 'previous', 'night_sold' => 0, 'night_total' => 2, 'neighbour_sold' => 2, 'neighbour_total' => 2], $occupancy->proof(Days::date('2027-03-02'), OrphanProof::PREVIOUS)->toArray());
        self::assertSame(['source' => 'pricing', 'neighbour' => 'previous', 'night_sold' => 0, 'night_total' => 2, 'neighbour_sold' => 2, 'neighbour_total' => 2], $occupancy->proofPreviousPast(Days::date('2027-03-02'))->toArray());
        self::assertSame(['source' => 'pricing', 'neighbour' => 'previous', 'night_sold' => 0, 'night_total' => 2, 'previous_past' => '2027-03-02'], $occupancy->proofPreviousPast(Days::date('2027-03-03'))->toArray(), 'no invented numbers for a night the calendar does not hold');
    }

    public function testThePricingSystemPushesOnlyWhatItsConnectorCarriesForTheConfiguredRooms(): void
    {
        $sync = new PricingSync(['*'], [], ['Cottage']);
        $capability = new StaySyncCapability(StaySyncField::MinStay, StaySyncField::ArrivalDeparture, StaySyncField::MinStay);

        self::assertCount(2, $capability->fields);
        self::assertTrue($sync->pushes(StaySyncField::MinStay, 'Deluxe', $capability));
        self::assertFalse($sync->pushes(StaySyncField::ArrivalDeparture, 'Deluxe', $capability));
        self::assertTrue($sync->pushes(StaySyncField::ArrivalDeparture, 'Cottage', $capability));
        self::assertFalse($sync->pushes(StaySyncField::MaxStay, 'Cottage', $capability));
        self::assertFalse($sync->pushes(StaySyncField::MinStay, 'Deluxe', StaySyncCapability::none()));
    }

    public function testFindingsSplitIntoIssuesAndAcceptedReasons(): void
    {
        $accepted = array_filter(StayFinding::cases(), static fn (StayFinding $f): bool => $f->isAccepted());

        self::assertSame([StayFinding::Held, StayFinding::IsolatedDay, StayFinding::OrphanAccepted, StayFinding::ClosedDayOrphanAccepted], array_values($accepted));
    }
}
