<?php

declare(strict_types=1);

namespace Ritmo\Tests\Unit\Domain\Report;

use PHPUnit\Framework\TestCase;
use Ritmo\Domain\Model\InventoryNight;
use Ritmo\Domain\Model\Property;
use Ritmo\Domain\Model\RoomType;
use Ritmo\Domain\Report\OccupancyWindows;
use Ritmo\Domain\Report\WindowOccupancy;
use Ritmo\Domain\Support\Days;
use Ritmo\Tests\Unit\Domain\EngineSample;

/**
 * Sample inn: Deluxe has 4 units, Standard 6. Goals of the rule set (schema defaults): 60% in
 * 7 days, 45% in 15, 30% in 30, 20% in 45 and 10% in 60.
 */
final class OccupancyWindowsTest extends TestCase
{
    private const TODAY = '2027-03-03';

    /**
     * @param array<int, int> $availableByOffset days from today => units still available
     *
     * @return list<InventoryNight>
     */
    private static function nights(array $availableByOffset): array
    {
        $nights = [];
        foreach ($availableByOffset as $offset => $available) {
            $nights[] = new InventoryNight(Days::add(Days::date(self::TODAY), $offset), $available);
        }

        return $nights;
    }

    public function testCountsSoldOverCapacityInEachWindow(): void
    {
        $inventory = [
            'Deluxe' => self::nights(array_fill(0, 10, 1)),
            'Standard' => self::nights(array_fill(0, 10, 6)),
        ];

        $report = (new OccupancyWindows(EngineSample::rules()))->measure(EngineSample::property(), [EngineSample::deluxe(), EngineSample::standard()], $inventory, Days::date(self::TODAY));

        self::assertSame('inn', $report->propertyId);
        self::assertSame(self::TODAY, Days::key($report->today));
        self::assertSame([7, 15, 30, 45, 60], array_keys($report->property));
        self::assertSame(['Deluxe', 'Standard'], array_keys($report->rooms));

        $week = $report->rooms['Deluxe'][7];
        self::assertSame([7, 21, 28, 75.0, 60.0], [$week->days, $week->sold, $week->capacity, $week->percent, $week->goal], '3 of 4 units for 7 nights');
        self::assertTrue($week->meetsGoal());
        self::assertSame([30, 40, 75.0], [$report->rooms['Deluxe'][15]->sold, $report->rooms['Deluxe'][15]->capacity, $report->rooms['Deluxe'][15]->percent], 'only the 10 nights reported count');
        self::assertSame([0, 42, 0.0], [$report->rooms['Standard'][7]->sold, $report->rooms['Standard'][7]->capacity, $report->rooms['Standard'][7]->percent]);
        self::assertFalse($report->rooms['Standard'][7]->meetsGoal());

        $property = $report->property[7];
        self::assertSame([21, 70, 30.0], [$property->sold, $property->capacity, $property->percent]);
        self::assertFalse($property->meetsGoal());
        self::assertSame([30, 100, 30.0], [$report->property[60]->sold, $report->property[60]->capacity, $report->property[60]->percent]);
        self::assertTrue($report->property[60]->meetsGoal(), '30% against a goal of 10%');
        self::assertSame([7, 15], array_map(static fn (WindowOccupancy $window): int => $window->days, $report->windowsUnderGoal()), '30% sold: under the goals of 60% and 45%, at the goal of 30%');
    }

    public function testTheWindowStartsTodayAndPastNightsAreLeftOut(): void
    {
        $inventory = ['Deluxe' => self::nights([-1 => 0, 0 => 0, 6 => 0, 7 => 0])];

        $report = (new OccupancyWindows(EngineSample::rules()))->measure(EngineSample::property(), [EngineSample::deluxe()], $inventory, Days::date(self::TODAY));

        self::assertSame([8, 8], [$report->rooms['Deluxe'][7]->sold, $report->rooms['Deluxe'][7]->capacity], 'today and the 6th day after it; not yesterday, not the 7th');
        self::assertSame(12, $report->rooms['Deluxe'][15]->sold);
    }

    public function testAnOverbookedNightCountsAsFullAndPercentagesKeepOneDecimal(): void
    {
        $inventory = ['Deluxe' => self::nights([0 => -1, 1 => 4, 2 => 3])];

        $week = (new OccupancyWindows(EngineSample::rules()))->measure(EngineSample::property(), [EngineSample::deluxe()], $inventory, Days::date(self::TODAY))->rooms['Deluxe'][7];

        self::assertSame([5, 12], [$week->sold, $week->capacity], '4 (not 5) + 0 + 1');
        self::assertSame(41.7, $week->percent);
    }

    public function testMoreUnitsAvailableThanTheRoomHasCountAsNothingSold(): void
    {
        $week = (new OccupancyWindows(EngineSample::rules()))->measure(EngineSample::property(), [EngineSample::deluxe()], ['Deluxe' => self::nights([0 => 9])], Days::date(self::TODAY))->rooms['Deluxe'][7];

        self::assertSame([0, 4], [$week->sold, $week->capacity]);
    }

    public function testANightReportedTwiceCountsOnce(): void
    {
        $nights = array_merge(self::nights([0 => 0]), self::nights([0 => 4]));

        $week = (new OccupancyWindows(EngineSample::rules()))->measure(EngineSample::property(), [EngineSample::deluxe()], ['Deluxe' => $nights], Days::date(self::TODAY))->rooms['Deluxe'][7];

        self::assertSame([4, 4], [$week->sold, $week->capacity]);
    }

    public function testARoomWithoutInventoryHasNoPercentage(): void
    {
        $report = (new OccupancyWindows(EngineSample::rules()))->measure(EngineSample::property(), [EngineSample::deluxe()], [], Days::date(self::TODAY));

        self::assertSame([0, 0], [$report->rooms['Deluxe'][7]->sold, $report->rooms['Deluxe'][7]->capacity]);
        self::assertNull($report->rooms['Deluxe'][7]->percent);
        self::assertNull($report->rooms['Deluxe'][7]->meetsGoal());
        self::assertNull($report->property[30]->percent);
        self::assertSame([], $report->windowsUnderGoal(), 'nothing measured is not a missed goal');
    }

    public function testRoomsOfOtherPropertiesAndUnknownInventoryAreIgnored(): void
    {
        $foreign = new RoomType('other', 'Suite', 'Suite', 2, '', '', 900.0, 300.0, 1500.0, 400.0);
        $inventory = ['Deluxe' => self::nights([0 => 0]), 'Suite' => self::nights([0 => 0]), 'Ghost' => self::nights([0 => 0])];

        $report = (new OccupancyWindows(EngineSample::rules()))->measure(EngineSample::property(), [EngineSample::deluxe(), $foreign], $inventory, Days::date(self::TODAY));

        self::assertSame(['Deluxe'], array_keys($report->rooms));
        self::assertSame(4, $report->property[7]->capacity);
    }

    public function testAPropertyWithItsOwnGoalsUsesItsOwnWindows(): void
    {
        $property = new Property('inn', 'Sample Inn', 'UTC', 'EUR', occupancyGoals: [3 => 80.0, 10 => 50.0]);

        $report = (new OccupancyWindows(EngineSample::rules()))->measure($property, [EngineSample::deluxe()], ['Deluxe' => self::nights(array_fill(0, 10, 2))], Days::date(self::TODAY));

        self::assertSame([3, 10], array_keys($report->property));
        self::assertSame([6, 12, 50.0, 80.0], [$report->property[3]->sold, $report->property[3]->capacity, $report->property[3]->percent, $report->property[3]->goal]);
        self::assertFalse($report->property[3]->meetsGoal());
        self::assertTrue($report->property[10]->meetsGoal(), 'exactly at the goal');
    }
}
