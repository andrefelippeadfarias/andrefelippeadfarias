<?php

declare(strict_types=1);

namespace Ritmo\Tests\Unit\Application\Write;

use PHPUnit\Framework\TestCase;
use Ritmo\Application\Write\Schedule\CalendarChange;
use Ritmo\Application\Write\Schedule\WriteSchedule;
use Ritmo\Application\Write\Schedule\WriteStep;
use Ritmo\Application\Write\WriteConfig;
use Ritmo\Contracts\WriteOperation;
use Ritmo\Domain\Calendar\Stay\ArrivalDepartureChange;
use Ritmo\Domain\Calendar\Stay\ChangeTarget;
use Ritmo\Domain\Calendar\Stay\StayChange;
use Ritmo\Domain\Calendar\Stay\StayReason;
use Ritmo\Domain\Model\Restriction;
use Ritmo\Domain\Plan\Payload\CallKind;
use Ritmo\Domain\Plan\Payload\ListingWrite;
use Ritmo\Domain\Plan\Payload\OverrideWrite;
use Ritmo\Domain\Plan\Payload\Payload;
use Ritmo\Domain\Plan\Payload\RoomCall;
use Ritmo\Domain\Plan\Payload\Slicer;
use Ritmo\Domain\Support\Days;
use Ritmo\Tests\Unit\Domain\EngineSample;

/**
 * The order of a write run: recalculations asked first, each deletion followed right after by the
 * rewrite of its nights (one group key), tonight, tomorrow and the rest, the horizon edge in calls
 * of its own, the PMS in blocks, the listing changes last.
 */
final class WriteScheduleTest extends TestCase
{
    private static function rule(string $date, int $min = -10): OverrideWrite
    {
        return new OverrideWrite(Days::date($date), 'step', $min, null, null, null, null, 'EUR');
    }

    /**
     * @return list<WriteStep>
     */
    private static function schedule(array $pms = [], ?WriteConfig $config = null): array
    {
        $payload = new Payload(
            [new RoomCall(CallKind::Delete, 'Deluxe', 'pricing-1', 'pms', [Days::date('2027-03-10'), Days::date('2027-03-11')])],
            [
                new RoomCall(CallKind::Write, 'Deluxe', 'pricing-1', 'pms', [self::rule('2027-03-03'), self::rule('2027-03-10', -20), self::rule('2027-03-12'), self::rule('2028-03-01')]),
                new RoomCall(CallKind::Write, 'Standard', 'pricing-2', 'pms', [self::rule('2027-03-04')]),
            ],
            [new ListingWrite('Deluxe', 'pricing-1', 'pms', ['base' => 1050.0])],
        );
        $today = Days::date('2027-03-03');
        $slices = (new Slicer(70))->slice($payload, $today);

        return (new WriteSchedule($config ?? new WriteConfig()))->build($slices, $today, $pms, ['pricing-2']);
    }

    public function testTheStepsComeInTheirOrder(): void
    {
        $steps = self::schedule();

        self::assertSame(
            ['recalculation', 'delete', 'rewrite', 'D0', 'D1', '1/1', 'edge', 'listing'],
            array_map(static fn (WriteStep $s): string => $s->label, $steps)
        );
        self::assertSame(range(1, 8), array_map(static fn (WriteStep $s): int => $s->number, $steps));
        self::assertSame(WriteOperation::Recalculation, $steps[0]->operation);
        self::assertSame(['pricing-2' => []], $steps[0]->nights);
        self::assertSame(WriteOperation::ListingUpdate, $steps[7]->operation);
    }

    public function testADeletionIsFollowedByTheRewriteOfItsNightsInOneGroup(): void
    {
        [, $delete, $rewrite] = self::schedule();

        self::assertNotSame('', $delete->groupKey);
        self::assertSame($delete->groupKey, $rewrite->groupKey);
        self::assertSame(['pricing-1' => ['2027-03-10', '2027-03-11']], $delete->nights);
        self::assertSame(['pricing-1' => ['2027-03-10']], $rewrite->nights, 'only the nights the plan rewrites');
        self::assertSame('-20', $rewrite->payload[0]['min_price']);
        self::assertTrue($rewrite->isGroupRewrite());
    }

    public function testTheRestNeverRepeatsANightOfTheGroupAndTheEdgeGoesAlone(): void
    {
        $steps = self::schedule();
        $rest = $steps[5];
        $edge = $steps[6];

        self::assertSame(['pricing-1' => ['2027-03-12']], $rest->nights);
        self::assertSame(['pricing-1' => ['2028-03-01']], $edge->nights);
        self::assertSame('', $rest->groupKey);
    }

    public function testThePmsGoesInBlocksAfterThePricingSystem(): void
    {
        $night = Days::date('2027-03-05');
        $pms = [
            new CalendarChange('Deluxe', 'pms-1', $night, $night, minStay: 2, minStayBefore: 1),
            new CalendarChange('Standard', 'pms-2', $night, Days::date('2027-03-06'), restriction: Restriction::NoCheckin),
            new CalendarChange('Deluxe', 'pms-1', Days::date('2027-03-07'), Days::date('2027-03-07'), available: 3),
        ];
        $steps = self::schedule($pms, new WriteConfig(pmsMaxRangesPerPost: 2));
        $blocks = array_values(array_filter($steps, static fn (WriteStep $s): bool => $s->operation === WriteOperation::PmsCalendar));

        self::assertCount(2, $blocks);
        self::assertSame(['pms 1/2', 'pms 2/2'], [$blocks[0]->label, $blocks[1]->label]);
        self::assertSame(['pms-1' => ['2027-03-05'], 'pms-2' => ['2027-03-05', '2027-03-06']], $blocks[0]->nights);
        self::assertSame([['roomId' => 'pms-1', 'from' => '2027-03-05', 'to' => '2027-03-05', 'minStay' => 2], ['roomId' => 'pms-2', 'from' => '2027-03-05', 'to' => '2027-03-06', 'override' => 'noCheckIn']], $blocks[0]->payload);
        self::assertSame(['minStay', 'override'], $blocks[0]->fields());
        self::assertSame('listing', end($steps)->label);
    }

    public function testThePmsChangesOfTheStayRulesBecomeCalendarChanges(): void
    {
        $night = Days::date('2027-03-06');
        $rooms = ['Deluxe' => EngineSample::deluxe()];
        $changes = CalendarChange::fromStay([
            new StayChange('Deluxe', $night, ChangeTarget::Pms, StayReason::ArrivalDay, 2, 1, alsoPricing: true),
            ArrivalDepartureChange::forPms('Deluxe', $night, StayReason::ClosedDay, Restriction::None, Restriction::NoCheckinCheckout),
            new StayChange('Deluxe', $night, ChangeTarget::Pricing, StayReason::ArrivalDay, 2),
        ], $rooms);

        self::assertCount(2, $changes, 'the pricing system\'s changes are plan items, not PMS changes');
        self::assertSame(['roomId' => 'pms-1', 'from' => '2027-03-06', 'to' => '2027-03-06', 'minStay' => 2], $changes[0]->range());
        self::assertSame(1, $changes[0]->minStayBefore);
        self::assertTrue($changes[0]->alsoPricing);
        self::assertSame('noCheckInOrCheckOut', $changes[1]->range()['override']);
    }
}
