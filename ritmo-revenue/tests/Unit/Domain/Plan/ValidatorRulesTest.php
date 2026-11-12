<?php

declare(strict_types=1);

namespace Ritmo\Tests\Unit\Domain\Plan;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Ritmo\Domain\Calendar\Stay\OrphanProof;
use Ritmo\Domain\Plan\CalendarNight;
use Ritmo\Domain\Plan\FreshCalendar;
use Ritmo\Domain\Plan\IssueSubject;
use Ritmo\Domain\Plan\LastMinuteRule;
use Ritmo\Domain\Plan\ListingChange;
use Ritmo\Domain\Plan\ListingField;
use Ritmo\Domain\Plan\NightState;
use Ritmo\Domain\Plan\OrphanClaim;
use Ritmo\Domain\Plan\OrphanClaimKind;
use Ritmo\Domain\Plan\PercentRounding;
use Ritmo\Domain\Plan\Plan;
use Ritmo\Domain\Plan\PlanConfig;
use Ritmo\Domain\Plan\PlanDeletion;
use Ritmo\Domain\Plan\PlanIssue;
use Ritmo\Domain\Plan\Port\StrategyGuard;
use Ritmo\Domain\Plan\Validation\OrphanProofCheck;
use Ritmo\Domain\Plan\Validator;
use Ritmo\Domain\Support\Days;
use Ritmo\Tests\Unit\Domain\EngineSample;

/**
 * The rules of the validator beyond milestone 1, on the sample inn (see PlanSample): owner orders,
 * floor families, the last-minute discount, the fresh calendar, stays and orphan nights, arrival
 * and departure days, deletions and listing changes.
 *
 * Deluxe (tub): base 1000, listing 600 to 3000, safety limit 525, owner floors 800 (weekday) and
 * 1166.67 (weekend), quality floor 800 on weekend and holiday nights. Standard: base 500, listing
 * 300 to 1500. Wednesday 10 March is a weekday night that requires two nights.
 */
final class ValidatorRulesTest extends TestCase
{
    /**
     * @param array<string, mixed> $with
     *
     * @return list<string>
     */
    private static function errors(array $with, ?\Ritmo\Domain\Plan\ValidationContext $context = null, ?PlanConfig $config = null): array
    {
        return PlanSample::validate([PlanSample::item($with)], $context, $config)->errorCodes();
    }

    /**
     * @param array<string, mixed> $with
     *
     * @return list<string>
     */
    private static function warnings(array $with, ?\Ritmo\Domain\Plan\ValidationContext $context = null, ?PlanConfig $config = null): array
    {
        return PlanSample::validate([PlanSample::item($with)], $context, $config)->warningCodes();
    }

    public function testACitedOrderMustBeInForceAndAllowsOnlyWhatItReleases(): void
    {
        $orders = PlanSample::orders([
            ['id' => 'below', 'type' => 'rule', 'releases' => ['below_standard_floor']],
            ['id' => 'stay-only', 'type' => 'rule', 'releases' => ['stay']],
            ['id' => 'later', 'type' => 'rule', 'releases' => ['below_standard_floor'], 'from' => '2027-04-01'],
        ]);
        $context = PlanSample::context(orders: $orders);

        self::assertSame([PlanIssue::ORDER_NOT_IN_FORCE, PlanIssue::BELOW_OWNER_FLOOR], self::errors(['minPrice' => 700.0, 'orderId' => 'unknown'], $context));
        self::assertSame([PlanIssue::ORDER_NOT_IN_FORCE, PlanIssue::BELOW_OWNER_FLOOR], self::errors(['minPrice' => 700.0, 'orderId' => 'later'], $context), 'not in force on the night');
        self::assertSame([PlanIssue::BELOW_OWNER_FLOOR], self::errors(['minPrice' => 700.0, 'orderId' => 'stay-only'], $context), 'an order allows only what it releases');
        self::assertSame([], self::errors(['minPrice' => 700.0, 'orderId' => 'below'], $context));
        self::assertSame([PlanIssue::BELOW_FLOOR_BY_ORDER], self::warnings(['minPrice' => 700.0, 'orderId' => 'below'], $context));
        self::assertSame([PlanIssue::BELOW_SAFETY_LIMIT], self::errors(['minPrice' => 500.0, 'orderId' => 'below'], $context), 'no order goes under the safety limit');
    }

    public function testANightFrozenByAManualChangeIsNeverTouched(): void
    {
        $context = PlanSample::context(orders: PlanSample::orders([['id' => 'manual-1', 'type' => 'manual', 'rooms' => ['Deluxe'], 'from' => '2027-03-09', 'to' => '2027-03-11']]));

        self::assertSame([PlanIssue::NIGHT_FROZEN], self::errors([], $context));
        self::assertSame([], self::errors(['orderId' => 'manual-1'], $context), 'the manual change itself may be written');
        self::assertSame([], self::errors(['roomCode' => 'Standard', 'minPrice' => 400.0, 'before' => new NightState(450.0)], $context), 'another room');
    }

    public function testAStrategyOrderCountsWhereItsStrategyActsDownToItsMarketFloor(): void
    {
        $strategy = new class () implements StrategyGuard {
            public function backsStrategy(string $orderId): bool
            {
                return $orderId === 'plan';
            }

            public function actsOn(string $orderId, string $roomCode, DateTimeImmutable $night): bool
            {
                return Days::key($night) !== '2027-03-11';
            }

            public function marketFloor(string $orderId, string $roomCode, DateTimeImmutable $night): ?float
            {
                return Days::key($night) === '2027-03-10' ? 760.0 : null;
            }

            public function replacesOwnerFloors(string $roomCode, DateTimeImmutable $night): bool
            {
                return Days::weekday($night) === 4;
            }
        };
        $context = PlanSample::context(orders: PlanSample::orders([['id' => 'plan', 'type' => 'rule']]), strategies: $strategy);

        self::assertSame([], self::errors(['minPrice' => 750.0, 'orderId' => 'plan'], $context), 'the market floor less one percent of the base and one unit');
        self::assertSame([PlanIssue::BELOW_FLOOR_BY_MARKET_FLOOR], self::warnings(['minPrice' => 750.0, 'orderId' => 'plan'], $context));
        self::assertSame([PlanIssue::BELOW_MARKET_FLOOR], self::errors(['minPrice' => 740.0, 'orderId' => 'plan'], $context));
        self::assertSame([PlanIssue::BELOW_MARKET_FLOOR], self::errors(['date' => '2027-03-09', 'minPrice' => 750.0, 'orderId' => 'plan'], $context), 'no market floor that night');
        self::assertSame([PlanIssue::ORDER_OUT_OF_SCOPE, PlanIssue::BELOW_OWNER_FLOOR], self::errors(['date' => '2027-03-11', 'minPrice' => 750.0, 'orderId' => 'plan'], $context));
        self::assertSame([], self::errors(['date' => '2027-03-12', 'minPrice' => 900.0], $context), 'the strategy replaced the weekend owner floor');
        self::assertSame([PlanIssue::BELOW_OWNER_FLOOR], self::errors(['date' => '2027-03-12', 'minPrice' => 900.0]), 'without it, 1166.67');
    }

    public function testTheQualityFloorGivesWayToMarketEvidenceOrAnOrderWithAWarning(): void
    {
        $friday = ['date' => '2027-03-12', 'minPrice' => 790.0, 'before' => new NightState(900.0)];
        $evidence = 'market median 620 over 12 rivals tonight';

        self::assertSame([PlanIssue::BELOW_OWNER_FLOOR, PlanIssue::BELOW_QUALITY_FLOOR], self::errors($friday));
        self::assertSame([PlanIssue::BELOW_OWNER_FLOOR], self::errors($friday + ['marketEvidence' => $evidence]));
        self::assertContains(PlanIssue::BELOW_QUALITY_BY_MARKET, self::warnings($friday + ['marketEvidence' => $evidence]));
        self::assertContains(PlanIssue::BELOW_QUALITY_FLOOR, self::errors($friday + ['marketEvidence' => 'the market is much weaker this week']), 'evidence needs a number');
        self::assertContains(PlanIssue::BELOW_QUALITY_FLOOR, self::errors($friday + ['marketEvidence' => 'median 620']), 'evidence needs 20 characters');

        $orders = PlanSample::orders([['id' => 'below', 'type' => 'rule', 'releases' => ['below_standard_floor']]]);
        $result = PlanSample::validate([PlanSample::item($friday + ['orderId' => 'below'])], PlanSample::context(orders: $orders));
        self::assertSame([], $result->errorCodes());
        self::assertSame([PlanIssue::BELOW_FLOOR_BY_ORDER, PlanIssue::BELOW_QUALITY_BY_ORDER], $result->warningCodes());
    }

    public function testARawFloorUnderTheSafetyLimitIsRefusedEvenWhenItsPercentageRoundsAbove(): void
    {
        self::assertSame([PlanIssue::BELOW_SAFETY_LIMIT], self::errors(['minPrice' => 524.5]), 'sent as -47%: 530');
    }

    public function testTheLastMinuteRuleTakesAFloorUnderTheListingMinimum(): void
    {
        $config = static fn (array $lastUnit = []): PlanConfig => new PlanConfig(lastMinute: new LastMinuteRule(true, ['Standard'], 3, 0.1, 0.7, $lastUnit));
        $calendar = static fn (int $sold, string $night = '2027-03-05'): FreshCalendar => new FreshCalendar(['Standard|' . $night => new CalendarNight(350.0, 300.0, $sold, 6)]);
        $standard = static fn (array $with): array => $with + ['roomCode' => 'Standard', 'date' => '2027-03-05', 'minPrice' => 280.0, 'before' => new NightState(350.0)];

        self::assertSame([PlanIssue::BELOW_LISTING_MIN], self::errors($standard([])), 'off by default');
        self::assertSame([PlanIssue::LAST_MINUTE_WITHOUT_CALENDAR], self::errors($standard([]), PlanSample::context(), $config()));
        self::assertSame([], self::errors($standard([]), PlanSample::context($calendar(2)), $config()));
        self::assertSame([PlanIssue::LAST_MINUTE_TOO_DEEP], self::errors($standard(['minPrice' => 260.0]), PlanSample::context($calendar(2)), $config()));
        self::assertSame([PlanIssue::LAST_MINUTE_SOLD_OUT], self::errors($standard([]), PlanSample::context($calendar(6)), $config()));
        self::assertSame([PlanIssue::LAST_MINUTE_TOO_SOLD], self::errors($standard([]), PlanSample::context($calendar(5)), $config()));
        self::assertSame([PlanIssue::LAST_MINUTE_LAST_UNIT_ONLY], self::errors($standard([]), PlanSample::context($calendar(2)), $config(['Standard'])));
        self::assertSame([PlanIssue::BELOW_LISTING_MIN], self::errors($standard(['date' => '2027-03-08']), PlanSample::context($calendar(2, '2027-03-08')), $config()), 'five days away');

        $tonight = PlanSample::validate([PlanSample::item($standard(['date' => PlanSample::TODAY]))], PlanSample::context($calendar(5, PlanSample::TODAY)), $config());
        self::assertSame([], $tonight->errorCodes());
        self::assertSame([PlanIssue::LAST_MINUTE_TOO_SOLD_TONIGHT], $tonight->warningCodes());
    }

    public function testADecisionThatReadsOccupancyNeedsAFreshCalendar(): void
    {
        $calendar = static fn (string $at): FreshCalendar => new FreshCalendar([], ['Deluxe' => PlanSample::instant($at)]);
        $old = $calendar('2027-03-03T02:00:00Z');

        self::assertSame([PlanIssue::STALE_CALENDAR], self::errors(['readsOccupancy' => true], PlanSample::context($old)));
        self::assertSame([PlanIssue::STALE_CALENDAR], self::errors(['reasonCode' => 'SOLD_OUT_PROTECTION'], PlanSample::context($old)), 'a reason code that reads occupancy');
        self::assertSame([PlanIssue::STALE_CALENDAR], self::errors(['minPrice' => 590.0, 'orderId' => 'below'], PlanSample::context($old, PlanSample::orders([['id' => 'below', 'type' => 'rule', 'releases' => ['below_standard_floor']]]))), 'under the listing minimum');
        self::assertSame([], self::errors([], PlanSample::context($old)), 'a decision that does not read occupancy');
        self::assertSame([], self::errors(['readsOccupancy' => true], PlanSample::context($calendar('2027-03-03T07:00:00Z'))), 'five hours old');
        self::assertSame([PlanIssue::STALE_CALENDAR_ACCEPTED], self::warnings(['readsOccupancy' => true], PlanSample::context($old, acceptStale: true)));
    }

    public function testTheStayOfTheNightFollowsItsRules(): void
    {
        $stay = static fn (string $night, int $nights, array $with = []): array => $with + ['date' => $night, 'minPrice' => null, 'minStay' => $nights, 'before' => new NightState(1000.0)];

        self::assertSame([PlanIssue::STAY_BELOW_REQUIRED], self::errors($stay('2027-03-10', 1)), 'Wednesday requires two');
        self::assertSame([PlanIssue::STAY_ABOVE_ONE_NIGHT], self::errors($stay('2027-03-09', 2)), 'Tuesday opens one night');
        self::assertSame([PlanIssue::STAY_ABOVE_REQUIRED], self::warnings($stay('2027-03-10', 3)));
        self::assertSame([PlanIssue::STAY_BELOW_REQUIRED], self::errors($stay('2027-03-13', 1)), 'the closed day keeps two');
        self::assertSame([PlanIssue::STAY_BELOW_REQUIRED], self::errors($stay('2027-03-18', 1)), 'the fair requires two');
        self::assertContains(PlanIssue::STAY_OUT_OF_RANGE, self::errors($stay('2027-03-10', 31)));
        self::assertSame([PlanIssue::STAY_NOT_IN_ITEM], self::warnings(['date' => '2027-03-13', 'minPrice' => 1200.0, 'before' => new NightState(1300.0)]), 'a Saturday floor without its stay');

        $orders = PlanSample::orders([
            ['id' => 'new', 'type' => 'rule', 'releases' => ['stay'], 'given_on' => '2027-03-02'],
            ['id' => 'old', 'type' => 'rule', 'releases' => ['stay'], 'given_on' => '2027-02-20'],
        ]);
        self::assertSame([], self::errors($stay('2027-03-10', 1, ['orderId' => 'new']), PlanSample::context(orders: $orders)));
        self::assertSame([PlanIssue::STAY_BELOW_REQUIRED], self::errors($stay('2027-03-10', 1, ['orderId' => 'old']), PlanSample::context(orders: $orders)), 'given before the arrival rule');
    }

    public function testAnOrphanNightNeedsItsProof(): void
    {
        $proof = static fn (int $next, string $readAt = '2027-03-03T09:00:00Z'): OrphanProof => new OrphanProof(OrphanProof::SOURCE_PMS, OrphanProof::NEXT, 4, 1, $next, readAt: PlanSample::instant($readAt));
        $orphan = static fn (?OrphanProof $p): array => ['minPrice' => null, 'minStay' => 1, 'orphan' => new OrphanClaim(OrphanClaimKind::NextNight, $p), 'before' => new NightState(1000.0, null, null, 2)];

        self::assertSame([], self::errors($orphan($proof(0))));
        self::assertSame([], self::errors($orphan($proof(0, '2027-03-02T20:00:00Z'))), 'read yesterday');
        self::assertSame([PlanIssue::ORPHAN_WITHOUT_PROOF, PlanIssue::STAY_BELOW_REQUIRED], self::errors($orphan(null)));
        self::assertSame([PlanIssue::ORPHAN_WITHOUT_PROOF, PlanIssue::STAY_BELOW_REQUIRED], self::errors($orphan($proof(1))), 'the next night is not sold out');
        self::assertSame([PlanIssue::ORPHAN_WITHOUT_PROOF, PlanIssue::STAY_BELOW_REQUIRED], self::errors($orphan($proof(0, '2027-02-28T09:00:00Z'))), 'read too long ago');
        $pricing = new OrphanProof(OrphanProof::SOURCE_PRICING, OrphanProof::NEXT, nightSold: 3, nightTotal: 4, neighbourSold: 4, neighbourTotal: 4);
        self::assertSame([], self::errors($orphan($pricing)));

        $contradicting = new FreshCalendar(['Deluxe|2027-03-11' => new CalendarNight(900.0, null, 2, 4)]);
        self::assertSame([PlanIssue::ORPHAN_CONTRADICTED, PlanIssue::STAY_BELOW_REQUIRED], self::errors($orphan($proof(0)), PlanSample::context($contradicting)));
        self::assertSame([PlanIssue::ORPHAN_NOT_ALLOWED, PlanIssue::STAY_BELOW_REQUIRED], self::errors($orphan($proof(0)) + ['date' => '2027-03-18']), 'not on a holiday unless allowed');

        $saturday = ['date' => '2027-03-13', 'minPrice' => null, 'minStay' => 1, 'before' => new NightState(1000.0, null, null, 2), 'orphan' => new OrphanClaim(OrphanClaimKind::ClosedDayArrival, new OrphanProof(OrphanProof::SOURCE_PMS, OrphanProof::PREVIOUS, 4, 2, 0, readAt: PlanSample::instant('2027-03-03T09:00:00Z')))];
        self::assertSame([], self::errors($saturday), 'the free side of the closed day');
    }

    public function testTheClosedDayOfTodayCountsThePastNightAsSoldOut(): void
    {
        $check = new OrphanProofCheck(Days::date('2027-03-06'), EngineSample::TIMEZONE, null);
        $past = new OrphanProof(OrphanProof::SOURCE_PMS, OrphanProof::PREVIOUS, 4, 2, null, readAt: PlanSample::instant('2027-03-06T08:00:00Z'), previousPast: Days::date('2027-03-05'));

        self::assertNull($check->failure($past, OrphanProof::PREVIOUS, Days::date('2027-03-06'), true));
        self::assertNotNull($check->failure($past, OrphanProof::PREVIOUS, Days::date('2027-03-13'), true), 'not the night before');
        self::assertNotNull((new OrphanProofCheck(Days::date('2027-03-05'), EngineSample::TIMEZONE, null))->failure($past, OrphanProof::PREVIOUS, Days::date('2027-03-06'), true), 'the night before has not passed');
        self::assertNotNull($check->failure($past, OrphanProof::PREVIOUS, Days::date('2027-03-06'), false), 'only on a closed day');
    }

    public function testTheArrivalAndDepartureDays(): void
    {
        $days = static fn (string $night, ?string $in, ?string $out, array $with = []): array => $with + ['date' => $night, 'minPrice' => null, 'checkInMask' => $in, 'checkOutMask' => $out];
        $orders = PlanSample::orders([
            ['id' => 'days', 'type' => 'rule', 'releases' => ['arrival_departure']],
            ['id' => 'orphans', 'type' => 'rule', 'releases' => ['orphan_stay']],
            ['id' => 'other', 'type' => 'rule', 'releases' => ['stay']],
        ]);
        $context = PlanSample::context(orders: $orders);
        $arrival = new OrphanClaim(OrphanClaimKind::ClosedDayArrival);

        self::assertSame([PlanIssue::MASK_OPENS_CLOSED_DAY, PlanIssue::MASK_OPENS_CLOSED_DAY], self::errors($days('2027-03-13', '1111111', null)), 'the departure left out is written open too');
        self::assertSame([], self::errors($days('2027-03-13', '1111111', null, ['orderId' => 'days']), $context));
        self::assertSame([], self::errors($days('2027-03-13', '1111111', '1111101', ['orderId' => 'orphans', 'orphan' => $arrival]), $context));
        self::assertSame([PlanIssue::MASK_BEYOND_ORPHAN_SIDE], self::errors($days('2027-03-13', '1111111', '1111111', ['orderId' => 'orphans', 'orphan' => $arrival]), $context));
        self::assertSame([PlanIssue::MASK_CLOSES_OPEN_NIGHT], self::errors($days('2027-03-10', null, '1101111')));
        self::assertSame([], self::errors($days('2027-03-10', null, '1101111', ['orderId' => 'days']), $context));
        self::assertSame([PlanIssue::MASK_CLOSES_OPEN_NIGHT], self::errors($days('2027-03-12', null, '1111011')));
        self::assertSame([PlanIssue::MASK_CLOSES_OPEN_DAY], self::errors($days('2027-03-12', null, '1111011', ['orderId' => 'other']), $context), 'Friday departure is kept open for the Deluxe');
        self::assertSame([PlanIssue::MASK_ALL_CLOSED], self::errors($days('2027-03-10', '0000000', null)));
        self::assertSame([], self::errors($days('2027-03-10', '1111101', '1111101')), 'closing another weekday changes nothing tonight');
    }

    public function testTheReasonOfTheDateRuleIsKeptOnlyByAnItemWithoutAPrice(): void
    {
        self::assertSame([PlanIssue::KEEP_REASON_WITH_PRICE], self::errors(['keepReason' => true]));
        self::assertSame([], self::errors(['minPrice' => null, 'minStay' => 2, 'keepReason' => true, 'before' => new NightState(1000.0)]));
    }

    public function testARoomWithoutDiscountsNeverGoesUnderItsPrice(): void
    {
        $config = new PlanConfig(noDiscountRooms: ['Deluxe']);

        self::assertSame([PlanIssue::NO_DISCOUNT_ROOM], self::errors([], null, $config));
        self::assertSame([PlanIssue::NO_DISCOUNT_ROOM], self::errors(['minPrice' => null, 'maxPrice' => 950.0], null, $config));
        self::assertSame([], self::errors(['minPrice' => 1000.0], null, $config));
        self::assertSame([PlanIssue::NO_DISCOUNT_ROOM], self::errors(['minPrice' => 1000.0, 'before' => null], null, $config), 'without the current price');
    }

    public function testTheDeletionsOfThePlan(): void
    {
        $deletion = static fn (string $night, string $room = 'Deluxe', string $reason = 'CEILING_RELEASE_DELETE'): PlanDeletion => new PlanDeletion($room, Days::date($night), $reason);
        $result = PlanSample::validate([], null, null, [
            $deletion('2027-03-02'), $deletion('2027-03-13'), $deletion('2027-03-18'), $deletion('2027-03-11'),
            $deletion('2027-03-11'), $deletion('2027-03-09', 'Nope'), $deletion('2027-03-09', 'Deluxe', ''),
        ]);

        self::assertSame([PlanIssue::PAST_DATE, PlanIssue::DUPLICATE_ITEM, PlanIssue::UNKNOWN_ROOM, PlanIssue::MISSING_REASON], $result->errorCodes());
        self::assertSame([0, 4, 5, 6], $result->rejected(IssueSubject::Deletion));
        self::assertSame([], $result->rejectedItems());
        self::assertSame([PlanIssue::DELETE_REMOVES_STAY, PlanIssue::DELETE_REMOVES_STAY, PlanIssue::DELETE_REMOVES_HOLIDAY_FLOOR], $result->warningCodes(), 'the Saturday and the fair; Thursday requires one night');
        self::assertSame(IssueSubject::Deletion, $result->warnings[0]->subject);
    }

    public function testTheListingChangesOfThePlan(): void
    {
        $orders = PlanSample::orders([['id' => 'listing', 'type' => 'rule', 'releases' => ['listing']], ['id' => 'stay', 'type' => 'rule', 'releases' => ['stay']]]);
        $change = static fn (ListingField $field, float $value, ?string $order = 'listing', string $room = 'Deluxe'): ListingChange => new ListingChange($room, $field, $value, 'OWNER_ORDER', '', $order);
        $result = PlanSample::validate([], PlanSample::context(orders: $orders, weekly: ['Deluxe' => ['base' => 1000.0, 'min' => 600.0]]), null, [], [
            $change(ListingField::Min, 640.0, null),
            $change(ListingField::Base, 1200.0),
            $change(ListingField::Min, 520.0),
            $change(ListingField::Base, 950.0, 'stay'),
            $change(ListingField::Max, 4000.0),
            $change(ListingField::Base, 550.0, 'listing', 'Standard'),
        ]);

        self::assertSame([
            PlanIssue::LISTING_NEEDS_ORDER,
            PlanIssue::LISTING_WEEKLY_CHANGE_ABOVE_LIMIT,
            PlanIssue::LISTING_WEEKLY_CHANGE_ABOVE_LIMIT,
            PlanIssue::BELOW_SAFETY_LIMIT,
            PlanIssue::ORDER_DOES_NOT_RELEASE,
        ], $result->errorCodes(), 'the maximum has no weekly reference, and the base of the Standard is over its minimum');
        self::assertSame([0, 1, 2, 3], $result->rejected(IssueSubject::Listing));
        self::assertSame([PlanIssue::LISTING_BASE_BELOW_MIN], PlanSample::validate([], PlanSample::context(orders: $orders), null, [], [$change(ListingField::Base, 280.0, 'listing', 'Standard')])->errorCodes());
    }

    public function testWithoutItsRejectedEntriesThePlanPasses(): void
    {
        $clock = PlanSample::clock();
        $plan = new Plan('inn', Days::date(PlanSample::TODAY), $clock->now(), [PlanSample::item(['minPrice' => 500.0]), PlanSample::item(['date' => '2027-03-11'])], [new PlanDeletion('Deluxe', Days::date('2027-03-01'), 'CEILING_RELEASE_DELETE')], [new ListingChange('Deluxe', ListingField::Min, 640.0, 'OWNER_ORDER')]);
        $validator = new Validator(EngineSample::rules(), $clock);
        $rooms = [EngineSample::deluxe(), EngineSample::standard()];

        $first = $validator->validate($plan, EngineSample::property(), $rooms, PlanSample::context());
        $second = $validator->validate($plan->without($first->rejectedItems(), $first->rejected(IssueSubject::Deletion), $first->rejected(IssueSubject::Listing)), EngineSample::property(), $rooms, PlanSample::context());

        self::assertFalse($first->isValid());
        self::assertTrue($second->isValid());
        self::assertSame([1, 0, 0], [count($second->plan->plan->items), count($second->plan->plan->deletions), count($second->plan->plan->listingChanges)]);
    }

    public function testTheNeutralConfigurationOfThePlanSection(): void
    {
        $config = PlanConfig::fromArray([]);

        self::assertEquals(new PlanConfig(), $config);
        self::assertFalse($config->lastMinute->enabled);
        self::assertSame(70, $config->sliceMaxDates);
        self::assertTrue($config->dependsOnOccupancy('SOLD_OUT_PROTECTION'));
        self::assertFalse($config->dependsOnOccupancy('pace_step'));
        foreach (PlanConfig::fields() as $field) {
            self::assertStringStartsWith('plan', $field->key);
            self::assertNotSame('', $field->label);
        }
    }

    public function testTheConfigurationRefusesWhatItCannotUse(): void
    {
        try {
            PlanConfig::fromArray(['validator' => ['max_min_stay' => 0, 'last_minute' => ['rooms' => ['not a code']], 'unknown' => 1], 'payload' => ['reason_max_length' => 300]]);
            self::fail('expected InvalidConfig');
        } catch (\Ritmo\Domain\Config\InvalidConfig $e) {
            $paths = array_map(static fn ($error): string => $error->path, $e->errors());
            sort($paths);
            self::assertSame(['plan.payload.reason_max_length', 'plan.validator.last_minute.rooms.0', 'plan.validator.max_min_stay', 'plan.validator.unknown'], $paths);
        }
    }

    public function testAnItemThatWritesAgainARuleDeletedFirstHasAnEffect(): void
    {
        $item = PlanSample::item(['roomCode' => 'Standard', 'minPrice' => null, 'minStay' => 2, 'reasonCode' => 'CEILING_RELEASE', 'before' => new NightState(450.0, 300.0, 1500.0, 2)]);
        $alone = PlanSample::validate([$item]);
        self::assertContains(PlanIssue::NO_EFFECT, $alone->errorCodes(), 'the same stay in force: nothing to write');

        $rewrite = PlanSample::validate([$item], null, null, [new PlanDeletion('Standard', $item->date, 'CEILING_RELEASE_DELETE')]);
        self::assertNotContains(PlanIssue::NO_EFFECT, $rewrite->errorCodes(), 'the deletion removes that rule first');
        self::assertNotNull($rewrite->plan);
        self::assertCount(1, (new PercentRounding())->apply($rewrite->plan, [EngineSample::deluxe(), EngineSample::standard()])->items, 'nor is it dropped as already in force');
    }
}
