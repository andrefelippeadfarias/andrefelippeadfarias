<?php

declare(strict_types=1);

namespace Ritmo\Tests\Unit\Admin\Editors;

require_once __DIR__ . '/stubs.php';

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Ritmo\Admin\Editors\FieldWidget;
use Ritmo\Admin\Editors\FormScope;
use Ritmo\Admin\Editors\RuleFormReader;
use Ritmo\Admin\Editors\RulesLayout;
use Ritmo\Domain\Config\RuleSet;
use Ritmo\Domain\Config\Schema;

final class RuleFormReaderTest extends TestCase
{
    public function testAShareIsTypedAsAPercentAndStoredExactly(): void
    {
        $field = Schema::ruleSet()->field('floors.fraction_of_reference_min');

        self::assertSame(FieldWidget::PERCENT, FieldWidget::kind($field, Schema::ruleSet()));
        self::assertSame('75', FieldWidget::display($field, 0.75));
        self::assertSame(0.7, FieldWidget::read($field, '70'));
        self::assertSame(['step' => '0.1', 'min' => '0', 'max' => '100'], FieldWidget::bounds($field));

        $result = RuleFormReader::read(Schema::ruleSet(), FormScope::tab(RulesLayout::SAFETY), RuleSet::defaults()->toArray(), ['floors' => ['fraction_of_reference_min' => '70']]);

        self::assertSame(0.7, $result->rules['floors']['fraction_of_reference_min']);
        self::assertSame(['floors.fraction_of_reference_min'], $result->touched);
        self::assertSame([], $result->errors);
    }

    public function testOnlyTheFieldsOfTheScopeChange(): void
    {
        $rules = RuleSet::defaults()->toArray();
        $posted = ['floors' => ['fraction_of_reference_min' => '50', 'quality_floor' => ['amount' => '300']], 'occupancy' => ['goals' => ['7' => '70']]];

        $general = RuleFormReader::read(Schema::ruleSet(), FormScope::section('floors'), $rules, $posted);

        self::assertSame($rules['floors']['fraction_of_reference_min'], $general->rules['floors']['fraction_of_reference_min'], 'a safety field is not edited from the floors section');
        self::assertSame(300.0, $general->rules['floors']['quality_floor']['amount']);
        self::assertSame($rules['occupancy'], $general->rules['occupancy'], 'another section is not read');
        self::assertSame(['floors.quality_floor.amount'], $general->touched);
    }

    public function testAValueThatDoesNotReadKeepsItsValueAndIsReported(): void
    {
        $rules = RuleSet::defaults()->toArray();

        $result = RuleFormReader::read(Schema::ruleSet(), FormScope::section('goals'), $rules, ['occupancy' => ['goals' => ['7' => 'sixty', '15' => '150']]]);

        self::assertSame([['path' => 'occupancy.goals.7', 'code' => 'number']], $result->errors);
        self::assertSame($rules['occupancy']['goals'][7], $result->rules['occupancy']['goals'][7]);
        self::assertSame(150.0, $result->rules['occupancy']['goals'][15], 'a value that reads goes into the draft; its range is checked at review');
    }

    public function testCheckboxesWeekdaysAndLinesRead(): void
    {
        $rules = RuleSet::defaults()->toArray();

        $calendar = RuleFormReader::read(Schema::ruleSet(), FormScope::tab(RulesLayout::CALENDAR), $rules, ['calendar' => ['weekend_days' => [RuleFormReader::PRESENT => '1', '5']]]);
        self::assertSame([5], $calendar->rules['calendar']['weekend_days']);
        self::assertSame(['calendar.weekend_days'], $calendar->touched);

        $none = RuleFormReader::read(Schema::ruleSet(), FormScope::tab(RulesLayout::CALENDAR), $rules, ['calendar' => ['weekend_days' => [RuleFormReader::PRESENT => '1']]]);
        self::assertSame([], $none->rules['calendar']['weekend_days'], 'nothing ticked is an empty list');

        $safety = RuleFormReader::read(Schema::ruleSet(), FormScope::tab(RulesLayout::SAFETY), $rules, ['write' => ['listing_changes_enabled' => '1'], 'safety' => ['never_raise_today' => '0']]);
        self::assertTrue($safety->rules['write']['listing_changes_enabled']);
        self::assertTrue($safety->rules['safety']['never_raise_today'], 'a field fixed by the product does not change');

        $runs = RuleFormReader::read(Schema::ruleSet(), FormScope::section('runs'), $rules, ['schedule' => ['light_times' => "10:53\n\n16:53\n"]]);
        self::assertSame(['10:53', '16:53'], $runs->rules['schedule']['light_times']);
    }

    public function testListItemsAreAddedRemovedAndKeptInOrder(): void
    {
        $rules = RuleSet::defaults()->toArray();
        $rules['floors']['owner'] = [
            ['night_type' => 'weekday', 'amount' => 300.0, 'price_plan' => 'sent', 'room_tags' => []],
            ['night_type' => 'weekend', 'amount' => 400.0, 'price_plan' => 'sent', 'room_tags' => []],
        ];
        $posted = ['floors' => ['owner' => [
            0 => ['night_type' => 'weekday', 'amount' => '320', 'price_plan' => 'sent', RuleFormReader::REMOVE => '0'],
            1 => [RuleFormReader::REMOVE => '1'],
            RuleFormReader::NEW_ITEM => ['night_type' => 'holiday', 'amount' => '500', 'price_plan' => 'public', RuleFormReader::ADD => '1'],
        ]]];

        $result = RuleFormReader::read(Schema::ruleSet(), FormScope::section('floors'), $rules, $posted);

        self::assertSame([['weekday', 320.0, 'sent'], ['holiday', 500.0, 'public']], array_map(static fn (array $f): array => [$f['night_type'], $f['amount'], $f['price_plan']], $result->rules['floors']['owner']));
        self::assertSame([], $result->rules['floors']['owner'][1]['room_tags'], 'a new item takes the defaults of what it does not post');

        $ignored = RuleFormReader::read(Schema::ruleSet(), FormScope::section('floors'), $rules, ['floors' => ['owner' => [RuleFormReader::NEW_ITEM => ['amount' => '1']]]]);
        self::assertSame([], $ignored->rules['floors']['owner'], 'posting only the empty item (not added) leaves no item');
    }

    public function testWidgetsOfEveryKind(): void
    {
        $schema = Schema::ruleSet();
        $kinds = [
            'occupancy.goals.7' => FieldWidget::NUMBER, 'write.listing_changes_enabled' => FieldWidget::CHECKBOX, 'schedule.full_time' => FieldWidget::TIME,
            'waves.scope' => FieldWidget::SELECT, 'calendar.holidays.*.start' => FieldWidget::DATE, 'calendar.weekend_days' => FieldWidget::WEEKDAYS,
            'price_bands.items.*.night_types' => FieldWidget::CHOICES, 'schedule.light_times' => FieldWidget::LINES, 'occupancy' => FieldWidget::GROUP,
            'floors.owner' => FieldWidget::ITEMS, 'price_factors.properties' => FieldWidget::MAP, 'calendar.holidays.*.name' => FieldWidget::TEXT,
        ];
        foreach ($kinds as $key => $kind) {
            self::assertSame($kind, FieldWidget::kind($schema->field($key), $schema), $key);
        }
        self::assertSame('holiday', FieldWidget::read($schema->field('calendar.holidays.*.night_type'), 'holiday'));
        self::assertNull(FieldWidget::read($schema->field('calendar.holidays.*.min_stay'), ''));
        self::assertSame('12.5', FieldWidget::display($schema->field('occupancy.goals.7'), 12.5));
        foreach ([['occupancy.goals.7', '1e3'], ['waves.scope', 'all'], ['calendar.holidays.*.start', '24/12/2026'], ['orders.manual_freeze_days', '2.5']] as [$key, $text]) {
            try {
                FieldWidget::read($schema->field($key), $text);
                self::fail($key);
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }
}
