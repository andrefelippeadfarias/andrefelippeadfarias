<?php

declare(strict_types=1);

namespace Ritmo\Tests\Unit\Admin\Editors;

require_once __DIR__ . '/stubs.php';

use OutOfBoundsException;
use PHPUnit\Framework\TestCase;
use Ritmo\Admin\Editors\ChoiceLabels;
use Ritmo\Admin\Editors\FieldPath;
use Ritmo\Admin\Editors\OrderText;
use Ritmo\Admin\Editors\RelationText;
use Ritmo\Admin\Editors\RulesLayout;
use Ritmo\Admin\Editors\ValueText;
use Ritmo\Application\Rules\SafetyFields;
use Ritmo\Domain\Config\FieldType;
use Ritmo\Domain\Config\RuleSet;
use Ritmo\Domain\Config\Schema;
use Ritmo\Domain\Market\Series\MarketFields;
use Ritmo\Domain\Orders\OrderReader;
use Ritmo\Domain\Orders\OrderRelease;
use Ritmo\Domain\Orders\OrderType;

final class EditorsTextTest extends TestCase
{
    public function testEveryFieldIsPlacedOnceAndEverySafetyKeyIsOnTheSafetyTab(): void
    {
        $keys = [...array_keys(Schema::ruleSet()->fields()), ...array_map(static fn ($f): string => $f->key, MarketFields::all())];
        self::assertGreaterThanOrEqual(457, count($keys));
        $tabs = [];
        foreach ($keys as $key) {
            $placement = RulesLayout::place($key);
            $tabs[$placement->tab][] = $key;
            if (SafetyFields::covers($key)) {
                self::assertSame(RulesLayout::SAFETY, $placement->tab, $key);
            }
        }
        $order = [RulesLayout::GENERAL, RulesLayout::SAFETY, RulesLayout::RELATIONS, RulesLayout::CALENDAR];
        self::assertSame($order, array_values(array_intersect($order, array_keys($tabs))), 'every tab edits some field');
        $sections = array_unique(array_map(static fn (string $k): string => RulesLayout::place($k)->section, $tabs[RulesLayout::GENERAL]));
        self::assertSame(array_keys(RulesLayout::SECTIONS), array_values(array_intersect(array_keys(RulesLayout::SECTIONS), $sections)), 'every section edits some field');
        self::assertSame('calendar', RulesLayout::place('stay.seasons.*.name')->tab);
        self::assertSame('stay', RulesLayout::place('stay.closed_days')->section);
        self::assertSame('floors', RulesLayout::place('price_factors.room_overrides')->section);
        self::assertTrue(RulesLayout::place('write.max_dates_per_call')->advanced);
        self::assertFalse(RulesLayout::place('occupancy.goals.7')->advanced);
        $this->expectException(OutOfBoundsException::class);
        RulesLayout::place('surprise.field');
    }

    public function testEveryOptionOfEveryChoiceHasAText(): void
    {
        $missing = [];
        foreach ([...Schema::ruleSet()->fields(), ...MarketFields::all()] as $field) {
            if ($field->type !== FieldType::Choice) {
                continue;
            }
            foreach ((array) $field->enum as $value) {
                $text = ChoiceLabels::of($field->key, $value);
                if ($text === (string) $value || $text === '') {
                    $missing[] = $field->key . '=' . $value;
                }
            }
        }
        self::assertSame([], $missing);
    }

    public function testAPathReadsAsLabelsAndItemNames(): void
    {
        $rules = RuleSet::defaults()->toArray();
        $rules['calendar']['holidays'] = [['name' => 'Carnival', 'start' => '2027-02-05', 'end' => '2027-02-08', 'night_type' => 'holiday', 'min_stay' => null, 'floor' => null]];
        $schema = Schema::ruleSet();

        self::assertSame('Occupancy › Occupancy goals › Next 7 days', FieldPath::label('occupancy.goals.7', $schema, $rules));
        self::assertSame($schema->field('calendar')->label . ' › ' . $schema->field('calendar.holidays')->label . ' › Carnival › ' . $schema->field('calendar.holidays.*.end')->label, FieldPath::label('calendar.holidays.0.end', $schema, $rules));
        self::assertSame($schema->field('floors')->label . ' › ' . $schema->field('floors.owner')->label . ' › 2', FieldPath::label('floors.owner.1', $schema, $rules));
        self::assertSame('ritmo-f-floors-owner-1-amount', FieldPath::anchor('floors.owner.1.amount'));
    }

    public function testValuesReadAsWords(): void
    {
        $schema = Schema::ruleSet();
        self::assertSame('75%', ValueText::of('floors.fraction_of_reference_min', 0.75, $schema, 'BRL'));
        self::assertSame('60%', ValueText::of('occupancy.goals.7', 60.0, $schema, 'BRL'));
        self::assertSame("R$\u{00A0}450", ValueText::of('calendar.holidays.*.floor', 450.0, $schema, 'BRL'));
        self::assertSame('Friday, Saturday', ValueText::of('calendar.weekend_days', [4, 5], $schema, 'BRL'));
        self::assertSame('On', ValueText::of('write.listing_changes_enabled', true, $schema, 'BRL'));
        self::assertSame('Holiday nights', ValueText::of('calendar.holidays.*.night_type', 'holiday', $schema, 'BRL'));
        self::assertSame('Carnival', ValueText::of('calendar.holidays.*', ['name' => 'Carnival', 'start' => '2027-02-05'], $schema, 'BRL'));
        self::assertSame('7 days', ValueText::of('orders.manual_freeze_days', 7, $schema, 'BRL'));
        self::assertSame('not set', ValueText::of('calendar.holidays.*.min_stay', null, $schema, 'BRL'));
    }

    public function testTheFiveRelationsReadAsSentences(): void
    {
        $rooms = ['room-a' => 'Room A', 'room-b' => 'Room B', 'room-c' => 'Room C'];
        $sentences = [
            'Room B never costs less than Room A plus 10%.' => ['type' => 'at_least', 'target' => 'room-b', 'params' => ['reference' => 'room-a', 'margin' => ['type' => 'percent', 'value' => 10.0], 'reference_min_units_left' => 1]],
            "Room B never costs less than Room A plus R$\u{00A0}20. This only holds while Room A has at least 2 units free." => ['type' => 'at_least', 'target' => 'room-b', 'params' => ['reference' => 'room-a', 'margin' => ['type' => 'amount', 'value' => 20.0], 'reference_min_units_left' => 2]],
            'Room B stays between Room A and Room C: from the cheaper one up to 40–60% of the way.' => ['type' => 'between', 'target' => 'room-b', 'params' => ['mode' => 'path', 'low_reference' => 'room-a', 'high_reference' => 'room-c', 'position_min' => 0.4, 'position_max' => 0.6]],
            'Room B stays between 80% and 95% of Room A.' => ['type' => 'between', 'target' => 'room-b', 'params' => ['mode' => 'percent_of', 'reference' => 'room-a', 'min_percent' => 80.0, 'max_percent' => 95.0]],
            'Room C costs more than the dearest of Room A and Room B: 15% on weekday nights, 20% on weekend nights and 25% on holiday nights.' => ['type' => 'premium_over_max', 'target' => 'room-c', 'params' => ['set' => ['rooms' => ['room-a', 'room-b'], 'room_tags' => null], 'premium_percent' => ['weekday' => 15.0, 'weekend' => 20.0, 'holiday' => 25.0]]],
            'Room C costs between 85.5% and 90% of the cheapest free room among the rooms tagged spa.' => ['type' => 'discount_band', 'target' => 'room-c', 'params' => ['set' => ['rooms' => null, 'room_tags' => ['spa']], 'lower_percent' => 85.5, 'upper_percent' => 90.0]],
            'When 80% of the units of Room A and Room B are sold, Room C goes to the market percentile 60 (and band-c stop that night).' => ['type' => 'occupancy_switch', 'target' => 'room-c', 'params' => ['trigger' => ['set' => ['rooms' => ['room-a', 'room-b']], 'unit' => 'percent', 'threshold' => 80.0], 'then' => ['kind' => 'market_percentile', 'by_night_group' => ['weekday' => ['segment' => 'comparable', 'percentile' => 60.0]]], 'suspends' => ['band-c']]],
            'When 3 units of Room A are sold, Room C keeps its price.' => ['type' => 'occupancy_switch', 'target' => 'room-c', 'params' => ['trigger' => ['set' => ['rooms' => ['room-a']], 'unit' => 'units', 'threshold' => 3.0], 'then' => ['kind' => 'hold']]],
        ];
        foreach ($sentences as $sentence => $relation) {
            self::assertSame($sentence, RelationText::describe($relation, $rooms, 'BRL'));
        }
        self::assertSame('This would make a loop: Room A depends on Room C, Room C depends on Room B and Room B depends on Room A (relations B over A, C over B and A over C). Turn one of them off or change it.', RelationText::loop(['room-a', 'room-b', 'room-c', 'room-a'], ['B over A', 'C over B', 'A over C'], $rooms));
    }

    public function testOrdersReadAsSentences(): void
    {
        $order = (new OrderReader())->read(['id' => 'order-1', 'type' => 'floor', 'rooms' => ['room-a'], 'from' => '2026-11-20', 'to' => '2026-11-22', 'floors' => ['room-a' => 450.0], 'releases' => []]);
        self::assertSame("Minimum price R$\u{00A0}450 for Room A (price sent to the channels).", OrderText::describe($order, ['room-a' => 'Room A'], 'BRL'));
        $target = (new OrderReader())->read(['id' => 'order-2', 'type' => 'target', 'rooms' => ['*'], 'from' => '2026-11-20', 'to' => '2026-11-22', 'percentile' => 60.0, 'floors' => ['room-a' => 400.0], 'releases' => []]);
        self::assertSame("Price at the market percentile 60, never below R$\u{00A0}400 for Room A on weak nights.", OrderText::describe($target, ['room-a' => 'Room A'], 'BRL'));
        self::assertSame('All rooms', OrderText::rooms($target, []));
        foreach (OrderType::cases() as $type) {
            self::assertNotSame($type->value, OrderText::type($type));
        }
        foreach (OrderRelease::cases() as $release) {
            self::assertStringEndsWith('.', OrderText::release($release));
        }
    }
}
