<?php

declare(strict_types=1);

namespace Ritmo\Tests\Unit\Domain\Calendar\Stay;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Ritmo\Domain\Calendar\Stay\StayConfig;
use Ritmo\Domain\Calendar\Stay\StayFields;
use Ritmo\Domain\Config\FieldType;
use Ritmo\Domain\Config\InvalidConfig;
use Ritmo\Domain\Config\Schema;
use Ritmo\Domain\Config\ValidationError;
use Ritmo\Domain\Model\Restriction;
use Ritmo\Domain\Support\Days;

final class StayConfigTest extends TestCase
{
    public function testTheDefaultsSwitchEveryRuleOff(): void
    {
        $config = StayConfig::fromArray([]);

        self::assertFalse($config->arrival->enabled);
        self::assertFalse($config->orphan->nextNight);
        self::assertSame([], $config->closedDays);
        self::assertSame([], $config->openDays);
        self::assertSame([], $config->packages);
        self::assertSame([], $config->seasons);
        self::assertSame([], $config->pricingSync->minStayRooms);
        self::assertSame([], $config->pricingSync->arrivalDepartureRooms);
        self::assertSame(1, $config->holidayMinStay);
        self::assertSame([14, 100], [$config->batch->urgentDays, $config->batch->maxRanges]);
        self::assertEquals($config, new StayConfig(), 'fromArray([]) equals the constructor defaults');
    }

    public function testEveryFieldIsDescribedAndTheSchemaIsConsistent(): void
    {
        $schema = new Schema(StayFields::all());

        foreach ($schema->fields() as $key => $field) {
            self::assertStringStartsWith('stay', $key);
            self::assertNotSame('', $field->label, $key);
            if ($field->type === FieldType::Integer && !str_contains($key, '.*')) {
                self::assertNotNull($field->min, "$key needs a range");
                self::assertNotNull($field->max, "$key needs a range");
            }
        }
        self::assertEquals(StayConfig::fields(), StayFields::all());
        self::assertArrayHasKey('arrival_rule', $schema->defaults()['stay']);
    }

    public function testAFullSectionIsRead(): void
    {
        $config = StayConfig::fromArray([
            'arrival_rule' => StaySample::ARRIVAL + ['properties' => ['annex' => ['4' => 3]], 'rooms' => ['Deluxe' => ['0' => 1]], 'held_weekdays' => ['Standard' => [2]]],
            'orphan' => ['next_night' => true, 'on_holidays' => true],
            'closed_days' => [StaySample::SATURDAY + ['open_dates' => ['2027-03-27' => 'none'], 'except_rooms' => ['Cottage']]],
            'open_days' => [['weekday' => 4, 'rooms' => ['Cottage']]],
            'packages' => [['name' => 'Winter package', 'nights' => [['date' => '2027-03-10', 'min_stay' => 3, 'max_stay' => 3, 'restriction' => 'no_checkout']]]],
            'seasons' => [['name' => 'High', 'start' => '2027-07-01', 'end' => '2027-07-31', 'weekdays' => [0, 1, 2, 3, 6], 'min_stay' => 2]],
            'pricing_sync' => ['min_stay_rooms' => ['*'], 'arrival_departure_rooms' => ['Cottage']],
            'keep_reason_markers' => ['Protected'],
        ]);

        self::assertSame([2, 1, 2, 1, 2, 2, 1], $config->arrival->byWeekday);
        self::assertSame(['annex' => [4 => 3]], $config->arrival->propertyWeekdays);
        self::assertSame(['Deluxe' => [0 => 1]], $config->arrival->roomWeekdays);
        self::assertSame(['Standard' => [2]], $config->arrival->heldWeekdays);
        self::assertSame(Restriction::None, $config->closedDays[0]->openDate(Days::date('2027-03-27')));
        self::assertSame(['Cottage'], $config->closedDays[0]->exceptRooms);
        self::assertSame(Restriction::NoCheckin, $config->closedDays[0]->orphanSide->whenClosedDaySoldOut);
        self::assertSame(3, $config->packages[0]->night('Deluxe', Days::date('2027-03-10'))?->maxStay);
        self::assertSame('High', $config->seasons[0]->name);
        self::assertSame(['protected'], $config->keepReasonMarkers);
    }

    /**
     * @param array<string, mixed> $section
     */
    #[DataProvider('invalidSections')]
    public function testInvalidSectionsAreRejectedWithThePath(array $section, string $path, string $code): void
    {
        try {
            StayConfig::fromArray($section);
            self::fail('expected InvalidConfig');
        } catch (InvalidConfig $error) {
            $found = array_map(static fn (ValidationError $e): string => $e->path . ' ' . $e->code, $error->errors());
            self::assertContains("$path $code", $found, implode('; ', $found));
        }
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string, string}>
     */
    public static function invalidSections(): iterable
    {
        yield 'unknown key' => [['surprise' => 1], 'stay.surprise', ValidationError::UNKNOWN];
        yield 'weekday out of range' => [['closed_days' => [['weekday' => 7]]], 'stay.closed_days.0.weekday', ValidationError::RANGE];
        yield 'closure that closes nothing' => [['closed_days' => [['weekday' => 5, 'restriction' => 'none']]], 'stay.closed_days.0.restriction', ValidationError::CHOICE];
        yield 'blackout is not a stay rule' => [['packages' => [['name' => 'P', 'nights' => [['date' => '2027-03-10', 'restriction' => 'blackout']]]]], 'stay.packages.0.nights.0.restriction', ValidationError::CHOICE];
        yield 'missing weekday of the table' => [['arrival_rule' => ['by_weekday' => ['0' => 1]]], 'stay.arrival_rule.by_weekday.1', ValidationError::REQUIRED];
        yield 'stay of zero nights' => [['arrival_rule' => ['rooms' => ['Deluxe' => ['2' => 0]]]], 'stay.arrival_rule.rooms.Deluxe.2', ValidationError::RANGE];
        yield 'bad weekday key' => [['arrival_rule' => ['properties' => ['inn' => ['8' => 2]]]], 'stay.arrival_rule.properties.inn.8', ValidationError::FORMAT];
        yield 'bad room code' => [['open_days' => [['weekday' => 4, 'rooms' => ['a room']]]], 'stay.open_days.0.rooms.0', ValidationError::FORMAT];
        yield 'open day without rooms' => [['open_days' => [['weekday' => 4, 'rooms' => []]]], 'stay.open_days.0.rooms', ValidationError::RANGE];
        yield 'rule ends before it starts' => [['arrival_rule' => ['valid_from' => '2027-03-10', 'valid_to' => '2027-03-01']], 'stay.arrival_rule.valid_to', ValidationError::ORDER];
        yield 'open date on another weekday' => [['closed_days' => [['weekday' => 5, 'open_dates' => ['2027-03-26' => 'none']]]], 'stay.closed_days.0.open_dates.2027-03-26', ValidationError::FORMAT];
        yield 'season ends before it starts' => [['seasons' => [['name' => 'S', 'start' => '2027-07-31', 'end' => '2027-07-01']]], 'stay.seasons.0.end', ValidationError::ORDER];
        yield 'package night twice' => [['packages' => [['name' => 'P', 'nights' => [['date' => '2027-03-10'], ['date' => '2027-03-10']]]]], 'stay.packages.0.nights', ValidationError::UNIQUE];
        yield 'text where a number goes' => [['batch' => ['max_ranges' => '100']], 'stay.batch.max_ranges', ValidationError::TYPE];
        yield 'bad date' => [['arrival_rule' => ['valid_from' => '2027-02-30']], 'stay.arrival_rule.valid_from', ValidationError::FORMAT];
    }
}
