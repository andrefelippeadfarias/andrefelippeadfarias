<?php

declare(strict_types=1);

namespace Ritmo\Tests\Unit\Domain\Calendar\Stay;

use PHPUnit\Framework\TestCase;
use Ritmo\Domain\Calendar\Stay\Occupancy;
use Ritmo\Domain\Calendar\Stay\OrphanKind;
use Ritmo\Domain\Calendar\Stay\PricingStayEntry;
use Ritmo\Domain\Calendar\Stay\PricingStayNight;
use Ritmo\Domain\Calendar\Stay\PricingStayPlan;
use Ritmo\Domain\Calendar\Stay\StayFinding;
use Ritmo\Domain\Calendar\Stay\StayReason;
use Ritmo\Domain\Model\DateOverride;
use Ritmo\Domain\Support\Days;

/**
 * 1 March 2027 is a Monday; Friday 12 and Saturday 13 March.
 */
final class PricingStayPlanTest extends TestCase
{
    private const STAY = [
        'arrival_rule' => StaySample::ARRIVAL + ['held_weekdays' => ['Standard' => [2]]],
        'orphan' => ['next_night' => true],
        'closed_days' => [StaySample::SATURDAY],
        'open_days' => [['weekday' => 4, 'rooms' => ['Cottage']]],
        'keep_reason_markers' => ['Protected'],
    ];

    public function testTheStayOnlyPlanReportsAClosedDayBelowItsOwnStayAndTheFullPlanRaisesIt(): void
    {
        $calendar = $this->calendar(['2027-03-12' => [1, 4, 0], '2027-03-13' => [1, 4, 0]]);

        $stayOnly = $this->plan('Deluxe', $calendar, '2027-03-13');
        $full = $this->plan('Deluxe', $calendar, '2027-03-13', stayOnly: false);

        self::assertSame([StayFinding::OwnStayOutsideStayPlan], $stayOnly[0]->findings);
        self::assertFalse($stayOnly[0]->hasItem());
        self::assertSame([StayFinding::StayUp, StayReason::OwnStay, 2, 1], [$full[0]->findings[0], $full[0]->stay?->reason, $full[0]->stay?->minStay, $full[0]->stay?->minStayBefore]);
    }

    public function testAHeldNightAndANightWithoutPricingData(): void
    {
        $entries = $this->plan('Standard', $this->calendar(['2027-03-03' => [1, 6, 0]]), '2027-03-03', '2027-03-04');

        self::assertSame([[StayFinding::Held], [StayFinding::NoPricingData]], array_map(static fn (PricingStayEntry $e): array => $e->findings, $entries));
        self::assertSame([[StayFinding::Held], []], array_map(static fn (PricingStayEntry $e): array => $e->accepted(), $entries));
        self::assertNull($entries[1]->stayBefore);
    }

    public function testTheTargetIsWrittenExplicitlyOnlyWhenTheDateRulesWereRead(): void
    {
        $calendar = $this->calendar(['2027-03-08' => [2, 4, 0]]);
        $monday = Days::date('2027-03-08');

        self::assertSame([], $this->plan('Deluxe', $calendar, '2027-03-08'));
        $explicit = $this->plan('Deluxe', $calendar, '2027-03-08', rules: []);
        self::assertSame([StayFinding::ExplicitStay, StayReason::ExplicitRule, 2], [$explicit[0]->findings[0], $explicit[0]->stay?->reason, $explicit[0]->stay?->minStay]);
        self::assertSame([], $this->plan('Deluxe', $calendar, '2027-03-08', rules: ['2027-03-08' => new DateOverride($monday, minStay: 2)]));
        self::assertSame(3, $this->plan('Deluxe', $this->calendar(['2027-03-08' => [3, 4, 0]]), '2027-03-08', rules: [])[0]->stay?->minStay, 'above the target the stay in force is written');
    }

    public function testAnOpenDayOpensTheWeekdayInTheMasks(): void
    {
        $friday = Days::date('2027-03-12');
        $listing = [$friday->format('Y-m-d') => new PricingStayNight($friday, 2, 0, 1, '1111111', '1111011')];

        $fromListing = $this->plan('Cottage', $listing, '2027-03-12')[0];
        self::assertSame([StayFinding::OpenDayMasks], $fromListing->findings);
        self::assertSame(['1111111', '1111111', true], [$fromListing->masks?->checkinMask, $fromListing->masks?->checkoutMask, $fromListing->masks?->keepReason], 'without the date rules read the mask item keeps the reason');

        $switchedOff = ['2027-03-12' => new PricingStayNight($friday, 2, 0, 1, '1111111', '1111011', false)];
        self::assertSame([], $this->plan('Cottage', $switchedOff, '2027-03-12'));

        $withFloor = $this->plan('Cottage', $listing, '2027-03-12', rules: ['2027-03-12' => new DateOverride($friday, minPercent: -10.0, checkinMask: '1111001', checkoutMask: '1111001')])[0];
        self::assertSame(['1111101', '1111101', true], [$withFloor->masks?->checkinMask, $withFloor->masks?->checkoutMask, $withFloor->masks?->keepReason]);
        $masksOnly = $this->plan('Cottage', $listing, '2027-03-12', rules: ['2027-03-12' => new DateOverride($friday, checkinMask: '1111111', checkoutMask: '1111001')])[0];
        self::assertSame(['1111101', false], [$masksOnly->masks?->checkoutMask, $masksOnly->masks?->keepReason], 'the item rewrites the masks: the new reason goes in');
        self::assertSame([], $this->plan('Deluxe', $listing, '2027-03-12'), 'not an open day of that room');
    }

    public function testAStayItemKeepsTheReasonOfARuleThatExplainsSomethingElse(): void
    {
        $calendar = $this->calendar(['2027-03-04' => [3, 4, 0]]);
        $thursday = Days::date('2027-03-04');
        $keep = fn (?DateOverride $rule): bool => $this->plan('Deluxe', $calendar, '2027-03-04', rules: $rule === null ? [] : ['2027-03-04' => $rule])[0]->stay->keepReason ?? false;

        self::assertFalse($keep(null));
        self::assertFalse($keep(new DateOverride($thursday, minStay: 3)));
        self::assertTrue($keep(new DateOverride($thursday, minStay: 3, reason: 'Protected night (owner)')));
        self::assertTrue($keep(new DateOverride($thursday, checkoutMask: '1111011')));
        self::assertTrue($keep(new DateOverride($thursday, price: 900.0, priceType: DateOverride::PRICE_FIXED)));
    }

    public function testOrphanItemsCarryTheProofOfThePricingCalendar(): void
    {
        $orphan = $this->plan('Deluxe', $this->calendar(['2027-03-03' => [2, 4, 1], '2027-03-04' => [1, 4, 4]]), '2027-03-03')[0];
        self::assertSame([StayFinding::OrphanNight, 1, OrphanKind::NextNight], [$orphan->findings[0], $orphan->stay?->minStay, $orphan->stay?->orphan]);
        self::assertSame(['source' => 'pricing', 'neighbour' => 'next', 'night_sold' => 1, 'night_total' => 4, 'neighbour_sold' => 4, 'neighbour_total' => 4], $orphan->stay?->proof?->toArray());

        $today = $this->plan('Deluxe', $this->calendar(['2027-03-13' => [2, 4, 1]]), '2027-03-13', today: '2027-03-13')[0];
        self::assertSame([StayFinding::ClosedDayOrphanToday, 1, OrphanKind::ClosedDay], [$today->findings[0], $today->stay?->minStay, $today->stay?->orphan]);
        self::assertSame(['source' => 'pricing', 'neighbour' => 'previous', 'night_sold' => 1, 'night_total' => 4, 'previous_past' => '2027-03-12'], $today->stay?->proof?->toArray());

        $accepted = $this->plan('Deluxe', $this->calendar(['2027-03-12' => [2, 4, 4], '2027-03-13' => [1, 4, 1]]), '2027-03-13')[0];
        self::assertSame([[StayFinding::ClosedDayOrphanAccepted], false], [$accepted->findings, $accepted->hasItem()]);
    }

    /**
     * @param array<string, array{int, int, int}> $table 'YYYY-MM-DD' => [stay, total, sold]
     *
     * @return array<string, PricingStayNight>
     */
    private function calendar(array $table): array
    {
        $out = [];
        foreach ($table as $date => [$stay, $total, $sold]) {
            $out[$date] = new PricingStayNight(Days::date($date), $stay, $sold, $total);
        }

        return $out;
    }

    /**
     * @param array<string, PricingStayNight> $calendar
     * @param array<string, DateOverride>|null $rules
     *
     * @return list<PricingStayEntry>
     */
    private function plan(string $room, array $calendar, string $from, ?string $to = null, ?array $rules = null, bool $stayOnly = true, string $today = '2027-03-01'): array
    {
        $units = ['Deluxe' => 4, 'Standard' => 6, 'Cottage' => 1][$room];

        return (new PricingStayPlan(StaySample::rules(self::STAY)))->plan(
            $room,
            $calendar,
            Occupancy::fromPricing($calendar, $units),
            Days::date($from),
            Days::date($to ?? $from),
            Days::date($today),
            null,
            $rules,
            $stayOnly,
        );
    }
}
