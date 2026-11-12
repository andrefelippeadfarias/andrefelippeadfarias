<?php

declare(strict_types=1);

namespace Ritmo\Tests\Unit\Domain\Config;

use OutOfBoundsException;
use PHPUnit\Framework\TestCase;
use Ritmo\Domain\Config\InvalidConfig;
use Ritmo\Domain\Config\RuleSet;
use Ritmo\Domain\Config\Schema;
use Ritmo\Domain\Model\NightType;
use Ritmo\Domain\Model\PaceStatus;
use Ritmo\Domain\Model\PricePlan;
use Ritmo\Domain\Model\Property;
use Ritmo\Domain\Model\RoomType;
use Ritmo\Domain\Support\Days;

final class RuleSetTest extends TestCase
{
    /**
     * A rule set where every group differs from the defaults, so that each accessor is seen to
     * read its own field.
     */
    private static function rules(): RuleSet
    {
        $data = Schema::ruleSet()->defaults();
        $data['occupancy']['goals'] = [60 => 12, 45 => 22, 30 => 33, 15 => 48, 7 => 66];
        $data['calendar']['weekend_days'] = [5, 6];
        $data['calendar']['holidays'] = [
            ['name' => 'Long weekend', 'start' => '2027-04-21', 'end' => '2027-04-24', 'night_type' => 'holiday', 'min_stay' => 3, 'floor' => 420],
            ['name' => 'Overlapping fair', 'start' => '2027-04-24', 'end' => '2027-04-26', 'night_type' => 'weekend', 'min_stay' => null, 'floor' => null],
        ];
        $data['pace']['band'] = 18;
        $data['pace']['very_behind'] = 28;
        $data['pace']['zero_sold_days'] = ['weekday' => 4, 'weekend' => 9, 'holiday' => 21];
        $data['pace']['ahead_near_days'] = 5;
        $data['pace']['group_pace'] = ['max_units' => 2, 'room_tags' => ['tub', 'view']];
        $data['pace']['k'] = 0.4;
        $data['pace']['max_step'] = [['min_days' => 0, 'percent' => 18], ['min_days' => 21, 'percent' => 6], ['min_days' => 5, 'percent' => 12]];
        $data['pace']['min_n'] = 8;
        $data['pace']['max_age_days'] = 9;
        $data['pace']['tolerance'] = 0.04;
        $data['pace']['protection_percentile'] = 88;
        $data['floors']['fraction_of_reference_min'] = 0.6;
        $data['floors']['owner'] = [
            ['night_type' => 'weekday', 'amount' => 300, 'price_plan' => 'discounted', 'room_tags' => ['tub']],
            ['night_type' => 'weekend', 'amount' => 450, 'price_plan' => 'public', 'room_tags' => []],
        ];
        $data['floors']['quality_floor'] = ['amount' => 500, 'price_plan' => 'sent', 'night_types' => ['holiday'], 'room_tags' => ['tub', 'view']];
        $data['floors']['holiday_floor'] = ['amount' => 380, 'price_plan' => 'discounted'];
        $data['price_factors']['properties']['seaside'] = [
            'public' => ['weekday' => 0.9, 'weekend' => 0.85, 'holiday' => 0.8],
            'discounted' => ['weekday' => 0.7, 'weekend' => 0.65, 'holiday' => 0.6],
        ];
        $data['price_factors']['room_overrides'] = [['room' => 'Loft', 'price_plan' => 'discounted', 'night_type' => 'holiday', 'factor' => 0.5]];
        $data['safety']['weekly_listing_change_max'] = 0.2;

        return RuleSet::fromArray($data);
    }

    public function testDefaultsAreTheSchemaDefaults(): void
    {
        $rules = RuleSet::defaults();

        self::assertEquals(Schema::ruleSet()->defaults(), $rules->toArray());
        self::assertTrue($rules->neverRaiseToday());
        self::assertSame([], $rules->holidays());
        self::assertSame([], $rules->ownerFloors());
    }

    public function testAnInvalidArrayIsRejectedWithEveryError(): void
    {
        $data = Schema::ruleSet()->defaults();
        $data['pace']['band'] = -5;
        $data['safety']['never_raise_today'] = false;

        try {
            RuleSet::fromArray($data);
            self::fail('Expected InvalidConfig.');
        } catch (InvalidConfig $e) {
            self::assertCount(2, $e->errors());
            self::assertStringContainsString('pace.band', $e->getMessage());
            self::assertStringContainsString('safety.never_raise_today', $e->getMessage());
        }
    }

    public function testAnIncompleteArrayIsRejected(): void
    {
        $this->expectException(InvalidConfig::class);
        RuleSet::fromArray(['pace' => ['band' => 20]]);
    }

    public function testOccupancyGoalsComeSortedAndAPropertyCanOverrideThem(): void
    {
        $rules = self::rules();
        $inherits = new Property('main', 'Main House', 'UTC', 'EUR');
        $own = new Property('annex', 'Annex', 'UTC', 'EUR', occupancyGoals: [30 => 40, 7 => 80]);

        self::assertSame([7 => 66.0, 15 => 48.0, 30 => 33.0, 45 => 22.0, 60 => 12.0], $rules->occupancyGoals());
        self::assertSame($rules->occupancyGoals(), $rules->occupancyGoalsFor($inherits));
        self::assertSame([7 => 80.0, 30 => 40.0], $rules->occupancyGoalsFor($own));
    }

    public function testCalendarAccessors(): void
    {
        $rules = self::rules();
        $holidays = $rules->holidays();

        self::assertSame([5, 6], $rules->weekendDays());
        self::assertCount(2, $holidays);
        self::assertSame('Long weekend', $holidays[0]->name);
        self::assertSame(['2027-04-21', '2027-04-22', '2027-04-23', '2027-04-24'], $holidays[0]->nights->keys());
        self::assertSame(NightType::Holiday, $holidays[0]->nightType);
        self::assertSame(3, $holidays[0]->minStay);
        self::assertSame(420.0, $holidays[0]->floor);
        self::assertNull($holidays[1]->minStay);
        self::assertNull($holidays[1]->floor);
    }

    public function testHolidayOnReturnsTheFirstPeriodThatCoversTheNight(): void
    {
        $rules = self::rules();

        self::assertNull($rules->holidayOn(Days::date('2027-04-20')));
        self::assertSame('Long weekend', $rules->holidayOn(Days::date('2027-04-21'))?->name);
        self::assertSame('Long weekend', $rules->holidayOn(Days::date('2027-04-24'))?->name, 'the first period of the list wins an overlap');
        self::assertSame('Overlapping fair', $rules->holidayOn(Days::date('2027-04-25'))?->name);
        self::assertNull($rules->holidayOn(Days::date('2027-04-27')));
    }

    public function testNightTypeUsesThePeriodThenTheWeekendDays(): void
    {
        $rules = self::rules();

        // 2027-04-21 is a Wednesday inside the holiday period.
        self::assertSame(NightType::Holiday, $rules->nightType(Days::date('2027-04-21')));
        // 2027-04-26 is a Monday inside a period priced as weekend.
        self::assertSame(NightType::Weekend, $rules->nightType(Days::date('2027-04-26')));
        // Outside any period: Saturday (5) and Sunday (6) are the weekend nights of this rule set.
        self::assertSame(NightType::Weekday, $rules->nightType(Days::date('2027-05-07')), 'Friday');
        self::assertSame(NightType::Weekend, $rules->nightType(Days::date('2027-05-08')), 'Saturday');
        self::assertSame(NightType::Weekend, $rules->nightType(Days::date('2027-05-09')), 'Sunday');
        self::assertSame(NightType::Weekday, $rules->nightType(Days::date('2027-05-10')), 'Monday');
    }

    public function testPaceAccessors(): void
    {
        $rules = self::rules();
        $defaults = Schema::ruleSet()->defaults();

        self::assertSame([60, 30, 14, 7, 3, 1, 0], array_keys($rules->paceCurve(NightType::Weekend)));
        self::assertEquals($defaults['pace']['curves']['weekend'], $rules->paceCurve(NightType::Weekend));
        self::assertSame(18.0, $rules->paceBand());
        self::assertSame(28.0, $rules->paceVeryBehind());
        self::assertSame(4, $rules->zeroSoldDays(NightType::Weekday));
        self::assertSame(9, $rules->zeroSoldDays(NightType::Weekend));
        self::assertSame(21, $rules->zeroSoldDays(NightType::Holiday));
        self::assertSame(5, $rules->aheadNearDays());
        self::assertSame(2, $rules->groupPaceMaxUnits());
        self::assertSame(['tub', 'view'], $rules->groupPaceRoomTags());
        self::assertSame(0.4, $rules->stepStrength());
        self::assertSame(8, $rules->minSampleSize());
        self::assertSame(9, $rules->maxSampleAgeDays());
        self::assertSame(0.04, $rules->tolerance());
        self::assertSame(88.0, $rules->protectionPercentile());
    }

    public function testTargetPercentileByTierStatusAndDaysLeft(): void
    {
        $rules = self::rules();
        $standard = Schema::ruleSet()->defaults()['pace']['target_percentile']['standard'];
        $premium = Schema::ruleSet()->defaults()['pace']['target_percentile']['premium'];

        self::assertEquals($premium, $rules->targetPercentiles('premium'));
        self::assertEquals($standard, $rules->targetPercentiles('unknown_tier'), 'an unknown tier uses the standard table');
        self::assertSame($premium['on_pace'], $rules->targetPercentile('premium', PaceStatus::OnPace, 3));
        self::assertSame($premium['behind'], $rules->targetPercentile('premium', PaceStatus::Behind, 3));
        self::assertSame($premium['very_behind'], $rules->targetPercentile('premium', PaceStatus::VeryBehind, 40));
        // "Ahead" becomes "ahead, close to the night" up to ahead_near_days (5 here), inclusive.
        self::assertSame($premium['ahead'], $rules->targetPercentile('premium', PaceStatus::Ahead, 6));
        self::assertSame($premium['ahead_near'], $rules->targetPercentile('premium', PaceStatus::Ahead, 5));
        self::assertSame($premium['ahead_near'], $rules->targetPercentile('premium', PaceStatus::Ahead, 0));
        self::assertSame($standard['ahead_near'], $rules->targetPercentile('standard', PaceStatus::Ahead, 1));
    }

    public function testMaxStepPercentPicksTheBandOfTheLeadTime(): void
    {
        $rules = self::rules();

        // Bands (given out of order): from 0 days 18%, from 5 days 12%, from 21 days 6%.
        $expected = [-3 => 18.0, 0 => 18.0, 4 => 18.0, 5 => 12.0, 20 => 12.0, 21 => 6.0, 400 => 6.0];
        foreach ($expected as $daysOut => $percent) {
            self::assertSame($percent, $rules->maxStepPercent($daysOut), "D$daysOut");
        }
    }

    public function testFloorAccessors(): void
    {
        $rules = self::rules();
        $tubRoom = new RoomType('seaside', 'Loft', 'Loft', 2, '', '', 800.0, 500.0, 3000.0, 450.0, ['tub']);
        $plainRoom = new RoomType('seaside', 'Twin', 'Twin', 4, '', '', 600.0, 400.0, 3000.0, 380.0);

        self::assertSame(0.6, $rules->referenceMinFraction());

        [$weekday, $weekend] = $rules->ownerFloors();
        self::assertSame(300.0, $weekday->amount);
        self::assertSame(PricePlan::Discounted, $weekday->pricePlan);
        self::assertTrue($weekday->appliesTo(NightType::Weekday, $tubRoom));
        self::assertFalse($weekday->appliesTo(NightType::Weekday, $plainRoom), 'the room lacks the tag');
        self::assertFalse($weekday->appliesTo(NightType::Weekend, $tubRoom), 'another night type');
        self::assertSame(PricePlan::PublicRate, $weekend->pricePlan);
        self::assertTrue($weekend->appliesTo(NightType::Weekend, $plainRoom), 'no tag means every room');

        $quality = $rules->qualityFloor();
        self::assertSame(500.0, $quality->amount);
        self::assertSame(PricePlan::Sent, $quality->pricePlan);
        self::assertSame([NightType::Holiday], $quality->nightTypes);
        self::assertFalse($quality->appliesTo(NightType::Holiday, $tubRoom), 'the room needs every tag listed');

        $holiday = $rules->holidayFloor();
        self::assertSame(380.0, $holiday->amount);
        self::assertSame(PricePlan::Discounted, $holiday->pricePlan);
        self::assertTrue($holiday->appliesTo(NightType::Holiday, $plainRoom));
        self::assertFalse($holiday->appliesTo(NightType::Weekend, $plainRoom));
    }

    public function testAFloorOfZeroIsOff(): void
    {
        $room = new RoomType('main', 'Twin', 'Twin', 4, '', '', 600.0, 400.0, 3000.0, 380.0);
        $rules = RuleSet::defaults();

        self::assertFalse($rules->qualityFloor()->appliesTo(NightType::Weekend, $room));
        self::assertFalse($rules->holidayFloor()->appliesTo(NightType::Holiday, $room));
    }

    public function testPriceFactorPrecedenceRoomThenPropertyThenFallback(): void
    {
        $rules = self::rules();

        self::assertSame(1.0, $rules->priceFactor('seaside', PricePlan::Sent, NightType::Holiday, 'Loft'), 'the sent plan is 1 by definition');
        self::assertSame(0.5, $rules->priceFactor('seaside', PricePlan::Discounted, NightType::Holiday, 'Loft'), 'room exception');
        self::assertSame(0.6, $rules->priceFactor('seaside', PricePlan::Discounted, NightType::Holiday, 'Twin'), 'property table');
        self::assertSame(0.6, $rules->priceFactor('seaside', PricePlan::Discounted, NightType::Holiday), 'property table without a room');
        self::assertSame(0.65, $rules->priceFactor('seaside', PricePlan::Discounted, NightType::Weekend, 'Loft'), 'the exception is for holidays only');
        self::assertSame(0.85, $rules->priceFactor('seaside', PricePlan::PublicRate, NightType::Weekend));
        self::assertSame(1.0, $rules->priceFactor('elsewhere', PricePlan::PublicRate, NightType::Weekend), 'a property without a table uses "*"');
    }

    public function testSafetyAccessors(): void
    {
        self::assertSame(0.2, self::rules()->weeklyListingChangeMax());
        self::assertTrue(self::rules()->neverRaiseToday());
    }

    public function testGetReadsAnyPath(): void
    {
        $rules = self::rules();

        self::assertSame(18.0, $rules->get('pace.band'));
        self::assertSame('Long weekend', $rules->get('calendar.holidays.0.name'));
        self::assertSame(100.0, $rules->get('pace.curves.holiday.0'));
        self::assertSame(0.9, $rules->get('price_factors.properties.seaside.public.weekday'));
        self::assertIsArray($rules->get('pace.target_percentile'));
    }

    public function testGetRejectsAnUnknownPath(): void
    {
        $this->expectException(OutOfBoundsException::class);
        self::rules()->get('pace.band.deeper');
    }

    public function testTheArrayIsCanonicalAndCannotBeChangedFromOutside(): void
    {
        $rules = self::rules();
        $copy = $rules->toArray();
        $copy['pace']['band'] = 99.0;

        self::assertSame(18.0, $rules->paceBand());
        self::assertSame(18.0, $rules->toArray()['pace']['band'], 'numbers are stored as floats');
        self::assertSame(['*', 'seaside'], array_keys($rules->toArray()['price_factors']['properties']), 'map keys are sorted');
        self::assertSame([7, 15, 30, 45, 60], array_keys($rules->toArray()['occupancy']['goals']), 'groups follow the schema order');
    }

    public function testEqualRulesHaveEqualHashesWhateverTheInputOrderOrNumberType(): void
    {
        $defaults = Schema::ruleSet()->defaults();
        $reordered = array_reverse($defaults, true);
        $reordered['pace'] = array_reverse($reordered['pace'], true);
        $reordered['pace']['band'] = (int) $defaults['pace']['band'];
        $reordered['pace']['target_percentile'] = array_reverse($defaults['pace']['target_percentile'], true);

        $changed = $defaults;
        $changed['pace']['band'] += 1;

        $hash = RuleSet::fromArray($defaults)->hash();
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $hash);
        self::assertSame($hash, RuleSet::fromArray($reordered)->hash());
        self::assertNotSame($hash, RuleSet::fromArray($changed)->hash());
    }
}
