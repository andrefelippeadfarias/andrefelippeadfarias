<?php

declare(strict_types=1);

namespace Ritmo\Tests\Unit\Domain\Write;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Ritmo\Domain\Config\InvalidConfig;
use Ritmo\Domain\Model\BookingRecord;
use Ritmo\Domain\Model\BookingStatus;
use Ritmo\Domain\Model\RoomType;
use Ritmo\Domain\Plan\CalendarNight;
use Ritmo\Domain\Plan\FreshCalendar;
use Ritmo\Domain\Plan\IssueSubject;
use Ritmo\Domain\Plan\ListingChange;
use Ritmo\Domain\Plan\ListingField;
use Ritmo\Domain\Plan\NightState;
use Ritmo\Domain\Plan\Plan;
use Ritmo\Domain\Plan\PlanDeletion;
use Ritmo\Domain\Plan\PlanItem;
use Ritmo\Domain\Support\Days;
use Ritmo\Domain\Write\Hold\CancellationHold;
use Ritmo\Domain\Write\Hold\CancellationWave;
use Ritmo\Domain\Write\Hold\HoldScope;
use Ritmo\Domain\Write\Hold\WaveConfig;
use Ritmo\Domain\Write\Hold\WaveHold;
use Ritmo\Domain\Write\State\ChangeField;
use Ritmo\Domain\Write\State\NightFields;
use Ritmo\Tests\Unit\Domain\EngineSample;

/**
 * A cancellation wave holds the cuts; raises and protections go ahead (task rule 9(k)).
 */
final class CancellationHoldTest extends TestCase
{
    private const NOW = '2027-03-03T12:00:00Z';

    public function testCutsAreHeldAndRaisesGoAhead(): void
    {
        $plan = self::plan([
            self::item('2027-03-10', 800.0, null, 1000.0, 900.0),
            self::item('2027-03-11', 950.0, null, 1000.0, 900.0),
            self::item('2027-03-12', null, 900.0, 1000.0, 700.0),
            self::item('2027-03-13', null, null, 1000.0, 700.0, 3),
        ]);
        $result = self::hold()->apply($plan, self::wave(), self::rooms(), null, self::now());

        self::assertSame([['2027-03-10', 'a lower floor'], ['2027-03-12', 'a ceiling under the current price']], array_map(static fn ($h): array => [Days::key($h->night), $h->why], $result->held));
        self::assertSame(['2027-03-11', '2027-03-13'], array_map(static fn (PlanItem $i): string => Days::key($i->date), $result->plan->items), 'a raise and a stay change');
    }

    public function testADeletionAndItsRewriteAreHeldTogether(): void
    {
        $plan = self::plan([self::item('2027-03-10', 700.0, null, 1000.0, 900.0), self::item('2027-03-11', 950.0, null, 1000.0, 900.0)], [
            new PlanDeletion('Deluxe', Days::date('2027-03-10'), 'release_ceiling'),
            new PlanDeletion('Deluxe', Days::date('2027-03-11'), 'release_ceiling'),
            new PlanDeletion('Deluxe', Days::date('2027-03-12'), 'release_ceiling'),
        ]);
        $calendar = new FreshCalendar([PlanItem::keyOf('Deluxe', Days::date('2027-03-12')) => new CalendarNight(1000.0, 900.0, 1, 4)]);
        $result = self::hold()->apply($plan, self::wave(), self::rooms(), $calendar, self::now());

        self::assertSame(['2027-03-11'], array_map(static fn (PlanDeletion $d): string => Days::key($d->date), $result->plan->deletions), 'its rewrite keeps the floor');
        self::assertSame(['2027-03-11'], array_map(static fn (PlanItem $i): string => Days::key($i->date), $result->plan->items));
        self::assertCount(2, array_filter($result->held, static fn ($h): bool => $h->subject === IssueSubject::Deletion));
    }

    public function testAListingValueLoweredIsHeld(): void
    {
        $plan = self::plan([], [], [new ListingChange('Deluxe', ListingField::Min, 550.0, 'listing_review', '', 'o1'), new ListingChange('Deluxe', ListingField::Max, 3200.0, 'listing_review', '', 'o1')]);
        $result = self::hold()->apply($plan, self::wave(), self::rooms(), null, self::now());

        self::assertCount(1, $result->held);
        self::assertSame(ListingField::Max, $result->plan->listingChanges[0]->field);
    }

    public function testAReleasedOrElapsedHoldHoldsNothing(): void
    {
        $plan = self::plan([self::item('2027-03-10', 800.0, null, 1000.0, 900.0)]);
        $released = new WaveHold(self::now()->modify('-2 hours'), self::now()->modify('-1 hour'), [], self::now());

        self::assertSame([], self::hold()->apply($plan, $released, self::rooms(), null, self::now())->held);
        self::assertSame([], (new CancellationHold(new WaveConfig(holdHours: 1)))->apply($plan, self::wave(), self::rooms(), null, self::now())->held);
        self::assertSame([], self::hold()->apply($plan, null, self::rooms(), null, self::now())->held);
    }

    public function testTheScopeCanBeTheCancelledNightsOnly(): void
    {
        $plan = self::plan([self::item('2027-03-10', 800.0, null, 1000.0, 900.0), self::item('2027-03-20', 800.0, null, 1000.0, 900.0)]);
        $result = (new CancellationHold(new WaveConfig(scope: HoldScope::CancelledNights)))->apply($plan, self::wave(), self::rooms(), null, self::now());

        self::assertSame(['2027-03-10'], array_map(static fn ($h): string => Days::key($h->night), $result->held));
    }

    public function testPmsOpeningsAreHeldOnTheCancelledNights(): void
    {
        $before = new NightFields([ChangeField::PMS_RESTRICTION => 'no_checkin_checkout', ChangeField::PMS_MIN_STAY => 3, ChangeField::PMS_AVAILABLE => 2]);
        $hold = self::hold();

        self::assertTrue($hold->holdsPms(self::wave(), 'Deluxe', Days::date('2027-03-10'), $before, new NightFields([ChangeField::PMS_RESTRICTION => 'none']), self::now()));
        self::assertTrue($hold->holdsPms(self::wave(), 'Deluxe', Days::date('2027-03-10'), $before, new NightFields([ChangeField::PMS_MIN_STAY => 2]), self::now()));
        self::assertTrue($hold->holdsPms(self::wave(), 'Deluxe', Days::date('2027-03-10'), $before, new NightFields([ChangeField::channelLimit('booking') => '2']), self::now()));
        self::assertFalse($hold->holdsPms(self::wave(), 'Deluxe', Days::date('2027-03-10'), $before, new NightFields([ChangeField::PMS_MIN_STAY => 4, ChangeField::PMS_RESTRICTION => 'blackout']), self::now()), 'closing goes ahead');
        self::assertFalse($hold->holdsPms(self::wave(), 'Deluxe', Days::date('2027-03-20'), $before, new NightFields([ChangeField::PMS_RESTRICTION => 'none']), self::now()), 'another night');
    }

    public function testAHoldIsBuiltFromWavesAndWidenedByNewerOnes(): void
    {
        $cancel = static fn (string $ref, string $at, string $arrival): BookingRecord => new BookingRecord($ref, 'Deluxe', Days::date($arrival), Days::add(Days::date($arrival), 2), BookingStatus::Cancelled, 'booking', '', 500.0, new DateTimeImmutable('2027-02-01T10:00:00Z'), new DateTimeImmutable($at), new DateTimeImmutable($at));
        $detector = new CancellationWave();
        $first = WaveHold::fromWaves($detector->detect([$cancel('1', '2027-03-03T08:00:00Z', '2027-03-10'), $cancel('2', '2027-03-03T08:30:00Z', '2027-03-10'), $cancel('3', '2027-03-03T09:00:00Z', '2027-03-15')], new DateTimeImmutable('2027-03-03T06:00:00Z')));
        $second = WaveHold::fromWaves($detector->detect([$cancel('4', '2027-03-03T11:00:00Z', '2027-03-20'), $cancel('5', '2027-03-03T11:10:00Z', '2027-03-20'), $cancel('6', '2027-03-03T11:20:00Z', '2027-03-20')], new DateTimeImmutable('2027-03-03T10:00:00Z')));

        self::assertNotNull($first);
        self::assertNotNull($second);
        $hold = $first->with($second);
        self::assertSame(['2027-03-03T08:00:00Z', '2027-03-03T11:20:00Z'], [$hold->since->format('Y-m-d\TH:i:s\Z'), $hold->lastCancellationAt->format('Y-m-d\TH:i:s\Z')]);
        self::assertTrue($hold->coversNight('Deluxe', Days::date('2027-03-16')));
        self::assertTrue($hold->coversNight('Deluxe', Days::date('2027-03-21')));
        self::assertFalse($hold->coversNight('Deluxe', Days::date('2027-03-12')));
        self::assertNull(WaveHold::fromWaves([]));
    }

    public function testTheConfigurationHasTheReferenceDefaults(): void
    {
        $config = WaveConfig::fromArray([]);

        self::assertSame([3, 3, null, HoldScope::Property], [$config->minCancellations, $config->windowHours, $config->holdHours, $config->scope]);
        self::assertSame(HoldScope::CancelledNights, WaveConfig::fromArray(['scope' => 'cancelled_nights', 'hold_hours' => 12])->scope);
        $this->expectException(InvalidConfig::class);
        WaveConfig::fromArray(['min_cancellations' => 1]);
    }

    private static function hold(): CancellationHold
    {
        return new CancellationHold();
    }

    private static function wave(): WaveHold
    {
        return new WaveHold(self::now()->modify('-3 hours'), self::now()->modify('-2 hours'), ['Deluxe|2027-03-10' => true, 'Deluxe|2027-03-11' => true]);
    }

    private static function item(string $night, ?float $floor, ?float $ceiling, float $price, float $floorInForce, ?int $stay = null): PlanItem
    {
        return new PlanItem('Deluxe', Days::date($night), $floor, $ceiling, $stay, 'pace_step', [], new NightState($price, $floorInForce));
    }

    /**
     * @param list<PlanItem>      $items
     * @param list<PlanDeletion>  $deletions
     * @param list<ListingChange> $listing
     */
    private static function plan(array $items, array $deletions = [], array $listing = []): Plan
    {
        return new Plan('inn', Days::date('2027-03-03'), self::now(), $items, $deletions, $listing);
    }

    /**
     * @return array<string, RoomType>
     */
    private static function rooms(): array
    {
        return ['Deluxe' => EngineSample::deluxe(), 'Standard' => EngineSample::standard()];
    }

    private static function now(): DateTimeImmutable
    {
        return new DateTimeImmutable(self::NOW);
    }
}
