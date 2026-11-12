<?php

declare(strict_types=1);

namespace Ritmo\Tests\Unit\Domain\Calendar;

use PHPUnit\Framework\TestCase;
use Ritmo\Domain\Calendar\HolidayCalendar;
use Ritmo\Domain\Config\Holiday;
use Ritmo\Domain\Model\NightType;
use Ritmo\Domain\Support\DateRange;
use Ritmo\Domain\Support\Days;
use Ritmo\Tests\Unit\Domain\EngineSample;

final class HolidayCalendarTest extends TestCase
{
    public function testNightsOutsideEveryPeriodFollowTheWeekendDays(): void
    {
        $calendar = new HolidayCalendar(EngineSample::rules());

        self::assertSame(NightType::Weekday, $calendar->nightType(Days::date('2027-03-04')), 'Thursday');
        self::assertSame(NightType::Weekend, $calendar->nightType(Days::date('2027-03-05')), 'Friday');
        self::assertSame(NightType::Weekend, $calendar->nightType(Days::date('2027-03-06')), 'Saturday');
        self::assertSame(NightType::Weekday, $calendar->nightType(Days::date('2027-03-07')), 'Sunday');
        self::assertFalse($calendar->isInPeriod(Days::date('2027-03-05')));
        self::assertNull($calendar->holidayOn(Days::date('2027-03-05')));
    }

    public function testANightInsideAPeriodTakesTheTypeThePeriodDeclares(): void
    {
        $calendar = new HolidayCalendar(EngineSample::rules());

        self::assertSame(NightType::Holiday, $calendar->nightType(Days::date('2027-03-18')), 'first night of the fair, a Thursday');
        self::assertSame(NightType::Holiday, $calendar->nightType(Days::date('2027-03-21')), 'last night of the fair');
        self::assertSame(NightType::Weekday, $calendar->nightType(Days::date('2027-03-22')), 'the day after');
        self::assertSame('Spring fair', $calendar->holidayOn(Days::date('2027-03-19'))?->name);
        self::assertTrue($calendar->isInPeriod(Days::date('2027-03-19')));

        self::assertSame(NightType::Weekend, $calendar->nightType(Days::date('2027-04-05')), 'a Monday priced as weekend by its period');
        self::assertTrue($calendar->isInPeriod(Days::date('2027-04-05')));
    }

    public function testTheFirstConfiguredPeriodWinsWhenTwoOverlap(): void
    {
        $rules = EngineSample::rules(static function (array $data): array {
            $data['calendar']['holidays'][] = ['name' => 'Late fair', 'start' => '2027-03-21', 'end' => '2027-03-23', 'night_type' => 'weekend', 'min_stay' => null, 'floor' => null];

            return $data;
        });
        $calendar = new HolidayCalendar($rules);

        self::assertSame('Spring fair', $calendar->holidayOn(Days::date('2027-03-21'))?->name);
        self::assertSame(NightType::Holiday, $calendar->nightType(Days::date('2027-03-21')));
        self::assertSame('Late fair', $calendar->holidayOn(Days::date('2027-03-22'))?->name);
    }

    public function testClassifyGivesTheTypeOfEveryNightOfARange(): void
    {
        $calendar = new HolidayCalendar(EngineSample::rules());

        self::assertSame(
            [
                '2027-03-16' => NightType::Weekday,
                '2027-03-17' => NightType::Weekday,
                '2027-03-18' => NightType::Holiday,
                '2027-03-19' => NightType::Holiday,
            ],
            $calendar->classify(DateRange::fromStrings('2027-03-16', '2027-03-19'))
        );
    }

    public function testPeriodsInListsThePeriodsThatTouchTheRange(): void
    {
        $calendar = new HolidayCalendar(EngineSample::rules());
        $names = static fn (array $periods): array => array_map(static fn (Holiday $holiday): string => $holiday->name, $periods);

        self::assertSame([], $names($calendar->periodsIn(DateRange::fromStrings('2027-03-01', '2027-03-17'))));
        self::assertSame(['Spring fair'], $names($calendar->periodsIn(DateRange::fromStrings('2027-03-21', '2027-04-04'))), 'touching the last night is enough');
        self::assertSame(['Spring fair', 'Local week'], $names($calendar->periodsIn(DateRange::fromStrings('2027-03-01', '2027-04-30'))));
    }
}
