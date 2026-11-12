<?php

declare(strict_types=1);

namespace Ritmo\Tests\Unit\Domain\Pricing;

use PHPUnit\Framework\TestCase;
use Ritmo\Domain\Config\RuleSet;
use Ritmo\Domain\Model\RoomType;
use Ritmo\Domain\Pricing\Floors;
use Ritmo\Domain\Support\Days;
use Ritmo\Tests\Unit\Domain\EngineSample;

/**
 * See EngineSample for the floors and factors of the sample. The Deluxe room has a tub, a listing
 * minimum of 600 and a safety limit of 525 (75% of 700).
 */
final class FloorsTest extends TestCase
{
    private const WEEKDAY = '2027-03-10';
    private const WEEKEND = '2027-03-12';
    private const HOLIDAY = '2027-03-18';

    public function testSafetyLimitIsAFractionOfTheReferenceMinimum(): void
    {
        $floors = new Floors(EngineSample::rules());

        self::assertSame(525.0, $floors->safetyLimit(EngineSample::deluxe()));
        self::assertSame(240.0, $floors->safetyLimit(EngineSample::standard()));
        self::assertSame(250.0, $floors->safetyLimit(new RoomType('inn', 'Odd', 'Odd room', 1, '', '', 500.0, 300.0, 900.0, 333.33)), '249.9975 kept with two decimals');
    }

    public function testHighestFloorIsTheListingMaximumRoundedDownToAWholePercentage(): void
    {
        $floors = new Floors(EngineSample::rules());

        self::assertSame(3000.0, $floors->highestFloor(EngineSample::deluxe()));
        self::assertSame(4996.98, $floors->highestFloor(new RoomType('inn', 'Odd', 'Odd room', 1, '', '', 1173.0, 900.0, 5000.0, 800.0)), '+326% of 1173');
    }

    public function testOwnerFloorOfTheNightTypeConvertedToTheSentPlan(): void
    {
        $floors = new Floors(EngineSample::rules());

        $weekday = $floors->hardFloor(EngineSample::deluxe(), Days::date(self::WEEKDAY));
        self::assertSame(800.0, $weekday->amount, '400 discounted / 0.5');
        self::assertSame(Floors::OWNER, $weekday->label);
        self::assertSame(
            [['label' => Floors::LISTING_MIN, 'amount' => 600.0], ['label' => Floors::REFERENCE_MIN_FRACTION, 'amount' => 525.0], ['label' => Floors::OWNER, 'amount' => 800.0]],
            $weekday->candidates
        );

        $weekend = $floors->hardFloor(EngineSample::deluxe(), Days::date(self::WEEKEND));
        self::assertSame(1166.67, $weekend->amount, '700 discounted / 0.6, above the quality floor of 800');
        self::assertSame(Floors::OWNER, $weekend->label);
        self::assertContains(['label' => Floors::QUALITY, 'amount' => 800.0], $weekend->candidates);
    }

    public function testRoomsTheRulesDoNotSelectKeepTheListingMinimum(): void
    {
        $floor = (new Floors(EngineSample::rules()))->hardFloor(EngineSample::standard(), Days::date(self::WEEKEND));

        self::assertSame(300.0, $floor->amount);
        self::assertSame(Floors::LISTING_MIN, $floor->label);
        self::assertCount(2, $floor->candidates, 'no tub: neither the owner floors nor the quality floor');
    }

    public function testHolidayFloorIsThePeriodsOwnOrTheGeneralWhicheverIsHigher(): void
    {
        $deluxe = EngineSample::deluxe();
        $night = Days::date(self::HOLIDAY);

        $own = (new Floors(EngineSample::rules()))->hardFloor($deluxe, $night);
        self::assertSame(1800.0, $own->amount, '900 of the period / 0.5');
        self::assertSame(Floors::HOLIDAY, $own->label);

        $withFloor = static fn (?float $floor): callable => static function (array $data) use ($floor): array {
            $data['calendar']['holidays'][0]['floor'] = $floor;

            return $data;
        };
        self::assertSame(1200.0, (new Floors(EngineSample::rules($withFloor(null))))->hardFloor($deluxe, $night)->amount, 'the general 600 / 0.5');
        self::assertSame(1200.0, (new Floors(EngineSample::rules($withFloor(500.0))))->hardFloor($deluxe, $night)->amount, 'never under the general floor');
    }

    public function testHolidayFloorUsesTheFactorOfTheRoom(): void
    {
        $floor = (new Floors(EngineSample::rules()))->hardFloor(EngineSample::standard(), Days::date(self::HOLIDAY));

        self::assertSame(2250.0, $floor->amount, '900 / 0.4, the exception of the room');
        self::assertSame(Floors::HOLIDAY, $floor->label);
    }

    public function testAPeriodPricedAsWeekendUsesTheWeekendRules(): void
    {
        $floors = new Floors(EngineSample::rules());
        $monday = $floors->hardFloor(EngineSample::deluxe(), Days::date('2027-04-05'));

        self::assertSame(1166.67, $monday->amount, 'the owner floor of weekend nights');
        self::assertSame(Floors::OWNER, $monday->label);
        self::assertSame(
            [Floors::LISTING_MIN, Floors::REFERENCE_MIN_FRACTION, Floors::OWNER, Floors::QUALITY],
            array_column($monday->candidates, 'label'),
            'the general holiday floor is for nights priced as holiday: this period has no floor of its own'
        );

        $rules = EngineSample::rules(static function (array $data): array {
            $data['calendar']['holidays'][1]['floor'] = 1000;

            return $data;
        });
        $withOwnFloor = (new Floors($rules))->hardFloor(EngineSample::deluxe(), Days::date('2027-04-05'));
        self::assertSame(1666.67, $withOwnFloor->amount, 'its own floor: 1000 / 0.6, the weekend factor');
        self::assertSame(Floors::HOLIDAY, $withOwnFloor->label);
    }

    public function testQualityFloorHoldsWhereItSelects(): void
    {
        $rules = EngineSample::rules(static function (array $data): array {
            $data['floors']['owner'] = [];

            return $data;
        });
        $floors = new Floors($rules);

        $weekend = $floors->hardFloor(EngineSample::deluxe(), Days::date(self::WEEKEND));
        self::assertSame(800.0, $weekend->amount);
        self::assertSame(Floors::QUALITY, $weekend->label);
        self::assertSame(Floors::LISTING_MIN, $floors->hardFloor(EngineSample::deluxe(), Days::date(self::WEEKDAY))->label, 'not on weekdays');
        self::assertSame(Floors::LISTING_MIN, $floors->hardFloor(EngineSample::standard(), Days::date(self::WEEKEND))->label, 'not without a tub');
    }

    public function testWithEveryRuleOffTheFloorIsTheListingMinimumOrTheSafetyLimit(): void
    {
        $floors = new Floors(RuleSet::defaults());

        self::assertSame(600.0, $floors->hardFloor(EngineSample::deluxe(), Days::date(self::WEEKEND))->amount);
        $highReference = new RoomType('inn', 'Odd', 'Odd room', 1, '', '', 900.0, 300.0, 1500.0, 800.0);
        $floor = $floors->hardFloor($highReference, Days::date(self::WEEKEND));
        self::assertSame(600.0, $floor->amount);
        self::assertSame(Floors::REFERENCE_MIN_FRACTION, $floor->label);
    }

    public function testOnATieTheMoreSpecificRuleNamesTheFloor(): void
    {
        $rules = EngineSample::rules(static function (array $data): array {
            $data['floors']['owner'][0]['amount'] = 300;

            return $data;
        });
        self::assertSame(Floors::OWNER, (new Floors($rules))->hardFloor(EngineSample::deluxe(), Days::date(self::WEEKDAY))->label, '300 / 0.5 = the listing minimum of 600');

        $sameAsSafety = new RoomType('inn', 'Odd', 'Odd room', 1, '', '', 900.0, 525.0, 1500.0, 700.0);
        self::assertSame(Floors::LISTING_MIN, (new Floors(RuleSet::defaults()))->hardFloor($sameAsSafety, Days::date(self::WEEKDAY))->label);
    }
}
