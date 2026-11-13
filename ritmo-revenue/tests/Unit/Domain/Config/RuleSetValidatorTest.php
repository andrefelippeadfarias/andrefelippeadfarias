<?php

declare(strict_types=1);

namespace Ritmo\Tests\Unit\Domain\Config;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Ritmo\Domain\Config\RuleSetValidator;
use Ritmo\Domain\Config\Schema;
use Ritmo\Domain\Config\ValidationError;

final class RuleSetValidatorTest extends TestCase
{
    /**
     * A complete valid rule set with one holiday, one owner floor and one room exception, so that
     * every list has an item to break.
     *
     * @return array<string, mixed>
     */
    private static function valid(): array
    {
        $data = Schema::ruleSet()->defaults();
        $data['calendar']['holidays'][] = ['name' => 'Festival', 'start' => '2027-03-01', 'end' => '2027-03-03', 'night_type' => 'holiday', 'min_stay' => 2, 'floor' => 400.0];
        $data['floors']['owner'][] = ['night_type' => 'weekend', 'amount' => 350.0, 'price_plan' => 'discounted', 'room_tags' => ['tub']];
        $data['price_factors']['properties']['main'] = $data['price_factors']['properties']['*'];
        $data['price_factors']['room_overrides'][] = ['room' => 'Deluxe', 'price_plan' => 'discounted', 'night_type' => 'holiday', 'factor' => 0.8];

        return $data;
    }

    /**
     * Sets (or, with the marker, removes) the value at a dotted path.
     *
     * @param array<int|string, mixed> $data
     *
     * @return array<int|string, mixed>
     */
    private static function with(array $data, string $path, mixed $value): array
    {
        $segments = explode('.', $path);
        $last = array_pop($segments);
        $node = &$data;
        foreach ($segments as $segment) {
            $node = &$node[$segment];
        }
        if ($value === '__remove__') {
            unset($node[$last]);
        } else {
            $node[$last] = $value;
        }

        return $data;
    }

    public function testTheBaseFixtureIsValid(): void
    {
        self::assertSame([], (new RuleSetValidator())->validate(self::valid()));
    }

    /**
     * @return array<string, array{string, mixed, string, string}> label => [path to change, new value, error path, error code]
     */
    public static function singleProblems(): array
    {
        $missing = '__remove__';

        return [
            'missing leaf' => ['pace.band', $missing, 'pace.band', ValidationError::REQUIRED],
            'missing group' => ['safety', $missing, 'safety', ValidationError::REQUIRED],
            'missing curve point' => ['pace.curves.weekend.14', $missing, 'pace.curves.weekend.14', ValidationError::REQUIRED],
            'missing item field' => ['calendar.holidays.0.start', $missing, 'calendar.holidays.0.start', ValidationError::REQUIRED],
            'null where not allowed' => ['pace.k', null, 'pace.k', ValidationError::REQUIRED],
            'unknown top-level key' => ['extras', ['x' => 1], 'extras', ValidationError::UNKNOWN],
            'unknown nested key' => ['pace.bandd', 20, 'pace.bandd', ValidationError::UNKNOWN],
            'unknown key in a list item' => ['calendar.holidays.0.color', 'red', 'calendar.holidays.0.color', ValidationError::UNKNOWN],
            'text for a number' => ['pace.band', '20', 'pace.band', ValidationError::TYPE],
            'boolean for a number' => ['pace.band', true, 'pace.band', ValidationError::TYPE],
            'fraction for a whole number' => ['pace.min_n', 5.5, 'pace.min_n', ValidationError::TYPE],
            'whole float for a whole number' => ['pace.min_n', 5.0, 'pace.min_n', ValidationError::TYPE],
            'number for a boolean' => ['safety.never_raise_today', 1, 'safety.never_raise_today', ValidationError::TYPE],
            'number for text' => ['calendar.holidays.0.name', 7, 'calendar.holidays.0.name', ValidationError::TYPE],
            'scalar for a group' => ['pace.curves', 5, 'pace.curves', ValidationError::TYPE],
            'scalar for a list' => ['calendar.holidays', 'none', 'calendar.holidays', ValidationError::TYPE],
            'map for a list' => ['calendar.weekend_days', ['a' => 4], 'calendar.weekend_days', ValidationError::TYPE],
            'scalar for a map' => ['pace.target_percentile', 50, 'pace.target_percentile', ValidationError::TYPE],
            'scalar for a list item' => ['calendar.holidays.0', 'Festival', 'calendar.holidays.0', ValidationError::TYPE],
            'below the minimum' => ['occupancy.goals.7', -1, 'occupancy.goals.7', ValidationError::RANGE],
            'above the maximum' => ['occupancy.goals.7', 100.5, 'occupancy.goals.7', ValidationError::RANGE],
            'exclusive minimum' => ['pace.k', 0, 'pace.k', ValidationError::RANGE],
            'k above one' => ['pace.k', 1.01, 'pace.k', ValidationError::RANGE],
            'weekday out of the week' => ['calendar.weekend_days', [4, 7], 'calendar.weekend_days.1', ValidationError::RANGE],
            'factor of zero' => ['price_factors.properties.main.public.weekday', 0, 'price_factors.properties.main.public.weekday', ValidationError::RANGE],
            'owner floor of zero' => ['floors.owner.0.amount', 0, 'floors.owner.0.amount', ValidationError::RANGE],
            'empty name' => ['calendar.holidays.0.name', '   ', 'calendar.holidays.0.name', ValidationError::RANGE],
            'name too long' => ['calendar.holidays.0.name', str_repeat('n', 121), 'calendar.holidays.0.name', ValidationError::RANGE],
            'too many tags' => ['floors.owner.0.room_tags', array_map(static fn (int $i): string => 'tag_' . $i, range(1, 21)), 'floors.owner.0.room_tags', ValidationError::RANGE],
            'no step band' => ['pace.max_step', [], 'pace.max_step', ValidationError::RANGE],
            'unknown night type' => ['calendar.holidays.0.night_type', 'festive', 'calendar.holidays.0.night_type', ValidationError::CHOICE],
            'unknown price plan' => ['floors.owner.0.price_plan', 'net', 'floors.owner.0.price_plan', ValidationError::CHOICE],
            'sent plan has no factor to override' => ['price_factors.room_overrides.0.price_plan', 'sent', 'price_factors.room_overrides.0.price_plan', ValidationError::CHOICE],
            'date in another format' => ['calendar.holidays.0.start', '01/03/2027', 'calendar.holidays.0.start', ValidationError::FORMAT],
            'date that does not exist' => ['calendar.holidays.0.end', '2027-02-30', 'calendar.holidays.0.end', ValidationError::FORMAT],
            'invalid UTF-8' => ['calendar.holidays.0.name', "Fest\xC3\x28", 'calendar.holidays.0.name', ValidationError::FORMAT],
            'tag that is not a slug' => ['floors.owner.0.room_tags', ['Has Tub'], 'floors.owner.0.room_tags.0', ValidationError::FORMAT],
            'tier key that is not a slug' => ['pace.target_percentile.Top Tier', ['ahead_near' => 90, 'ahead' => 80, 'on_pace' => 70, 'behind' => 60, 'very_behind' => 50], 'pace.target_percentile.Top Tier', ValidationError::FORMAT],
            'property key with a space' => ['price_factors.properties.my hotel', [], 'price_factors.properties.my hotel', ValidationError::FORMAT],
            'repeated weekend day' => ['calendar.weekend_days', [4, 5, 4], 'calendar.weekend_days', ValidationError::UNIQUE],
            'repeated tag' => ['floors.owner.0.room_tags', ['tub', 'tub'], 'floors.owner.0.room_tags', ValidationError::UNIQUE],
            'locked field changed' => ['safety.never_raise_today', false, 'safety.never_raise_today', ValidationError::LOCKED],
            'standard tier removed' => ['pace.target_percentile.standard', $missing, 'pace.target_percentile.standard', ValidationError::REQUIRED],
            'fallback property removed' => ['price_factors.properties.*', $missing, 'price_factors.properties.*', ValidationError::REQUIRED],
            'tier missing a status' => ['pace.target_percentile.premium.behind', $missing, 'pace.target_percentile.premium.behind', ValidationError::REQUIRED],
            'period ends before it starts' => ['calendar.holidays.0.end', '2027-02-28', 'calendar.holidays.0.end', ValidationError::ORDER],
            'very behind below the band' => ['pace.very_behind', 10, 'pace.very_behind', ValidationError::ORDER],
            'curve going down towards the night' => ['pace.curves.weekday.3', 80, 'pace.curves.weekday.3', ValidationError::ORDER],
            'curve higher far away than close' => ['pace.curves.holiday.60', 50, 'pace.curves.holiday.60', ValidationError::ORDER],
            'target higher when behind than on pace' => ['pace.target_percentile.standard.behind', 55, 'pace.target_percentile.standard.behind', ValidationError::ORDER],
            'no band from day zero' => ['pace.max_step', [['min_days' => 10, 'percent' => 5]], 'pace.max_step', ValidationError::REQUIRED],
            'two bands from the same day' => ['pace.max_step', [['min_days' => 0, 'percent' => 5], ['min_days' => 0, 'percent' => 9]], 'pace.max_step', ValidationError::UNIQUE],
            'same room exception twice' => [
                'price_factors.room_overrides.1',
                ['room' => 'Deluxe', 'price_plan' => 'discounted', 'night_type' => 'holiday', 'factor' => 0.9],
                'price_factors.room_overrides.1',
                ValidationError::UNIQUE,
            ],
        ];
    }

    #[DataProvider('singleProblems')]
    public function testOneProblemYieldsOneErrorAtItsPath(string $path, mixed $value, string $errorPath, string $code): void
    {
        $errors = (new RuleSetValidator())->validate(self::with(self::valid(), $path, $value));

        $found = array_map(static fn (ValidationError $error): string => $error->path . ' [' . $error->code . ']', $errors);
        self::assertContains($errorPath . ' [' . $code . ']', $found);
        self::assertCount(1, $errors, 'unexpected extra errors: ' . implode('; ', array_map('strval', $errors)));
        self::assertNotSame('', $errors[0]->message);
    }

    /**
     * @return array<string, array{string, mixed}>
     */
    public static function acceptedValues(): array
    {
        return [
            'whole number where a number is expected' => ['pace.band', 20],
            'limits are inclusive (0)' => ['occupancy.goals.60', 0],
            'limits are inclusive (100)' => ['occupancy.goals.7', 100],
            'k of exactly one' => ['pace.k', 1],
            'no weekend at all' => ['calendar.weekend_days', []],
            'whole week is weekend' => ['calendar.weekend_days', [0, 1, 2, 3, 4, 5, 6]],
            'optional stay left empty' => ['calendar.holidays.0.min_stay', null],
            'optional floor left empty' => ['calendar.holidays.0.floor', null],
            'one-night period' => ['calendar.holidays.0.end', '2027-03-01'],
            'period priced as weekend' => ['calendar.holidays.0.night_type', 'weekend'],
            'flat curve' => ['pace.curves.weekday', [60 => 100, 30 => 100, 14 => 100, 7 => 100, 3 => 100, 1 => 100, 0 => 100]],
            'very behind equal to the band' => ['pace.very_behind', 15],
            'target above 100' => ['pace.target_percentile.premium.ahead_near', 110],
            'accented name' => ['calendar.holidays.0.name', 'Réveillon à beira-mar'],
            'factor above one' => ['price_factors.properties.main.public.weekend', 1.12],
        ];
    }

    #[DataProvider('acceptedValues')]
    public function testValuesInsideTheRulesAreAccepted(string $path, mixed $value): void
    {
        self::assertSame([], array_map('strval', (new RuleSetValidator())->validate(self::with(self::valid(), $path, $value))));
    }

    public function testAnEmptyTierMapIsTooSmallAndLacksTheStandardTier(): void
    {
        $errors = (new RuleSetValidator())->validate(self::with(self::valid(), 'pace.target_percentile', []));

        self::assertSame(
            ['pace.target_percentile [range]', 'pace.target_percentile.standard [required]'],
            array_map(static fn (ValidationError $error): string => $error->path . ' [' . $error->code . ']', $errors)
        );
    }

    public function testEveryProblemIsReportedNotOnlyTheFirst(): void
    {
        $data = self::valid();
        $data['pace']['band'] = 'wide';
        $data['occupancy']['goals'][7] = 140;
        $data['calendar']['holidays'][0]['start'] = 'soon';
        unset($data['safety']['weekly_listing_change_max']);
        $data['mystery'] = true;

        $paths = array_map(static fn (ValidationError $error): string => $error->path, (new RuleSetValidator())->validate($data));
        sort($paths);

        self::assertSame(['calendar.holidays.0.start', 'mystery', 'occupancy.goals.7', 'pace.band', 'safety.weekly_listing_change_max'], $paths);
    }

    public function testCrossFieldRulesWaitForAStructurallyValidArray(): void
    {
        // The end date is unusable, so comparing it with the start would be meaningless (and a crash).
        $data = self::with(self::valid(), 'calendar.holidays.0.end', 20270301);
        $data['pace']['very_behind'] = 1;

        $errors = (new RuleSetValidator())->validate($data);

        self::assertCount(1, $errors);
        self::assertSame('calendar.holidays.0.end', $errors[0]->path);
    }

    public function testAnEmptyArrayReportsEveryTopLevelGroup(): void
    {
        $paths = array_map(static fn (ValidationError $error): string => $error->path, (new RuleSetValidator())->validate([]));

        self::assertSame(['occupancy', 'calendar', 'pace', 'floors', 'price_factors', 'safety', 'relations', 'price_bands', 'ladder', 'soldout', 'cadence', 'no_effect', 'orders', 'stay', 'plan', 'write', 'quota', 'verify', 'approval', 'third_party', 'waves', 'modes', 'schedule', 'light_run', 'alerts', 'market', 'reports'], $paths);
    }

    public function testAValidatorCanBeReused(): void
    {
        $validator = new RuleSetValidator();

        self::assertCount(1, $validator->validate(self::with(self::valid(), 'pace.band', 'x')));
        self::assertSame([], $validator->validate(self::valid()));
    }

    public function testAnErrorReadsAsPathAndMessage(): void
    {
        $errors = (new RuleSetValidator())->validate(self::with(self::valid(), 'occupancy.goals.7', 101));

        self::assertSame('occupancy.goals.7: must be at most 100', (string) $errors[0]);
    }
}
