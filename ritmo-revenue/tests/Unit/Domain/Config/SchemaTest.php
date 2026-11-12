<?php

declare(strict_types=1);

namespace Ritmo\Tests\Unit\Domain\Config;

use InvalidArgumentException;
use OutOfBoundsException;
use PHPUnit\Framework\TestCase;
use Ritmo\Domain\Config\Field;
use Ritmo\Domain\Config\FieldType;
use Ritmo\Domain\Config\RuleSetFields;
use Ritmo\Domain\Config\RuleSetValidator;
use Ritmo\Domain\Config\Schema;

final class SchemaTest extends TestCase
{
    public function testRuleSetSchemaCoversEveryGroupOfTheFirstTwoMilestones(): void
    {
        $schema = Schema::ruleSet();
        $expected = [
            'occupancy.goals.7', 'occupancy.goals.15', 'occupancy.goals.30', 'occupancy.goals.45', 'occupancy.goals.60',
            'calendar.weekend_days', 'calendar.holidays',
            'calendar.holidays.*.name', 'calendar.holidays.*.start', 'calendar.holidays.*.end',
            'calendar.holidays.*.night_type', 'calendar.holidays.*.min_stay', 'calendar.holidays.*.floor',
            'pace.band', 'pace.very_behind', 'pace.zero_sold_days.weekday', 'pace.group_pace.max_units', 'pace.group_pace.room_tags',
            'pace.target_percentile',
            'pace.target_percentile.*.ahead_near', 'pace.target_percentile.*.ahead', 'pace.target_percentile.*.on_pace',
            'pace.target_percentile.*.behind', 'pace.target_percentile.*.very_behind', 'pace.ahead_near_days',
            'pace.k', 'pace.max_step', 'pace.max_step.*.min_days', 'pace.max_step.*.percent',
            'pace.min_n', 'pace.max_age_days', 'pace.tolerance', 'pace.protection_percentile',
            'floors.fraction_of_reference_min', 'floors.owner', 'floors.owner.*.night_type', 'floors.owner.*.amount',
            'floors.owner.*.price_plan', 'floors.owner.*.room_tags', 'floors.quality_floor.amount',
            'floors.quality_floor.price_plan', 'floors.holiday_floor.amount', 'floors.holiday_floor.price_plan',
            'price_factors.properties', 'price_factors.properties.*.public.weekday',
            'price_factors.properties.*.discounted.holiday', 'price_factors.room_overrides',
            'safety.weekly_listing_change_max', 'safety.never_raise_today',
        ];
        foreach (['weekday', 'weekend', 'holiday'] as $type) {
            foreach ([60, 30, 14, 7, 3, 1, 0] as $days) {
                $expected[] = "pace.curves.$type.$days";
            }
        }

        foreach ($expected as $key) {
            self::assertTrue($schema->has($key), "missing field $key");
        }
        self::assertSame(['occupancy', 'calendar', 'pace', 'floors', 'price_factors', 'safety', 'relations', 'price_bands', 'ladder', 'soldout', 'cadence', 'no_effect', 'orders', 'stay', 'plan'], array_keys($schema->children('')));
    }

    public function testEveryFieldIsFullyDescribed(): void
    {
        foreach (Schema::ruleSet()->fields() as $key => $field) {
            self::assertSame($key, $field->key);
            self::assertNotSame('', $field->label, "$key has no label");
            if ($field->type === FieldType::Integer || $field->type === FieldType::Number) {
                self::assertNotNull($field->min, "$key has no minimum");
                self::assertNotNull($field->max, "$key has no maximum");
            }
            if ($field->type === FieldType::Choice) {
                self::assertNotEmpty($field->enum, "$key has no choices");
            }
            if ($field->type === FieldType::ListOf || $field->type === FieldType::Map) {
                self::assertNotNull($field->max, "$key has no size limit");
                self::assertTrue(Schema::ruleSet()->has($key . '.*'), "$key has no item definition");
            }
        }
    }

    public function testEveryFieldHasHelpOfItsOwnOrFromTheSectionAroundIt(): void
    {
        $schema = Schema::ruleSet();
        foreach ($schema->children('') as $key => $section) {
            self::assertNotSame('', $section->help, "the section $key has no help");
        }
        foreach ($schema->fields() as $key => $field) {
            $closest = $field;
            while ($closest->help === '' && $closest->parentKey() !== null) {
                $closest = $schema->field($closest->parentKey());
            }
            self::assertNotSame('', $closest->help, "$key has no help, and neither has any group around it");
            if (!$field->type->isContainer() && $field->parentKey() !== null) {
                self::assertNotSame($schema->field((string) strtok($key, '.'))->help, $closest->help, "$key is explained only by its top-level section");
            }
        }
    }

    public function testNeverRaiseTodayIsLockedOn(): void
    {
        $field = Schema::ruleSet()->field('safety.never_raise_today');

        self::assertTrue($field->locked);
        self::assertTrue($field->default);
        self::assertSame(FieldType::Boolean, $field->type);
    }

    public function testFieldNameAndParent(): void
    {
        $field = Schema::ruleSet()->field('pace.curves.weekend.7');

        self::assertSame('7', $field->name());
        self::assertSame('pace.curves.weekend', $field->parentKey());
        self::assertNull(Schema::ruleSet()->field('pace')->parentKey());
    }

    public function testChildrenAreReturnedInDefinitionOrder(): void
    {
        $schema = Schema::ruleSet();

        self::assertSame(RuleSetFields::GOAL_WINDOWS, array_keys($schema->children('occupancy.goals')));
        self::assertSame(RuleSetFields::CURVE_DAYS, array_keys($schema->children('pace.curves.holiday')));
        self::assertSame(['*'], array_keys($schema->children('calendar.holidays')));
        self::assertSame([], $schema->children('pace.band'));
    }

    public function testKeyForPathResolvesListIndexesAndMapKeys(): void
    {
        $schema = Schema::ruleSet();

        self::assertSame('pace.band', $schema->keyForPath('pace.band'));
        self::assertSame('calendar.holidays.*.end', $schema->keyForPath('calendar.holidays.2.end'));
        self::assertSame('pace.target_percentile.*.on_pace', $schema->keyForPath('pace.target_percentile.premium.on_pace'));
        self::assertSame('price_factors.properties.*.public.weekend', $schema->keyForPath('price_factors.properties.*.public.weekend'));
        self::assertSame('calendar.weekend_days.*', $schema->keyForPath('calendar.weekend_days.0'));
        self::assertNull($schema->keyForPath('pace.nope'));
        self::assertNull($schema->keyForPath('pace.band.deeper'));
        self::assertNull($schema->keyForPath('nope'));
    }

    public function testUnknownFieldThrows(): void
    {
        $this->expectException(OutOfBoundsException::class);
        Schema::ruleSet()->field('pace.nope');
    }

    public function testDefaultsAreACompleteValidRuleSet(): void
    {
        $defaults = Schema::ruleSet()->defaults();

        self::assertSame([], (new RuleSetValidator())->validate($defaults));
        self::assertTrue($defaults['safety']['never_raise_today']);
        self::assertSame([], $defaults['calendar']['holidays']);
        self::assertSame(100.0, $defaults['pace']['curves']['weekday'][0]);
    }

    public function testWithDefaultsFillsWhatIsMissingAtAnyDepth(): void
    {
        $schema = Schema::ruleSet();
        $partial = [
            'pace' => ['band' => 22, 'curves' => ['weekday' => [7 => 31]]],
            'calendar' => ['holidays' => [['name' => 'Festival', 'start' => '2027-03-01', 'end' => '2027-03-03']]],
            'floors' => ['owner' => [['night_type' => 'weekend', 'amount' => 400, 'price_plan' => 'public']]],
        ];

        $filled = $schema->withDefaults($partial);

        self::assertSame(22, $filled['pace']['band']);
        self::assertSame(31, $filled['pace']['curves']['weekday'][7]);
        self::assertSame($schema->defaults()['pace']['curves']['weekday'][60], $filled['pace']['curves']['weekday'][60]);
        self::assertSame($schema->defaults()['pace']['curves']['holiday'], $filled['pace']['curves']['holiday']);
        self::assertSame($schema->defaults()['occupancy'], $filled['occupancy']);
        self::assertSame('holiday', $filled['calendar']['holidays'][0]['night_type']);
        self::assertNull($filled['calendar']['holidays'][0]['min_stay']);
        self::assertNull($filled['calendar']['holidays'][0]['floor']);
        self::assertSame([], $filled['floors']['owner'][0]['room_tags']);
        self::assertSame([], (new RuleSetValidator())->validate($filled));
    }

    public function testWithDefaultsNeverInventsRequiredValuesNorDropsUnknownKeys(): void
    {
        $filled = Schema::ruleSet()->withDefaults([
            'calendar' => ['holidays' => [['name' => 'No dates']], 'typo' => 1],
            'pace' => 'not a group',
        ]);

        self::assertArrayNotHasKey('start', $filled['calendar']['holidays'][0]);
        self::assertSame(1, $filled['calendar']['typo']);
        self::assertSame('not a group', $filled['pace']);
    }

    public function testASchemaRejectsInconsistentDefinitions(): void
    {
        $group = new Field('a', FieldType::Group, 'A');
        $definitions = [
            'child before its parent' => [new Field('a.b', FieldType::Integer, 'B')],
            'defined twice' => [$group, new Field('a.b', FieldType::Integer, 'B'), new Field('a.b', FieldType::Integer, 'B')],
            'child of a leaf' => [$group, new Field('a.b', FieldType::Integer, 'B'), new Field('a.b.c', FieldType::Integer, 'C')],
            'wildcard under a group' => [$group, new Field('a.*', FieldType::Integer, 'Any')],
            'named child under a list' => [$group, new Field('a.l', FieldType::ListOf, 'L'), new Field('a.l.x', FieldType::Integer, 'X')],
            'empty container' => [$group],
        ];

        foreach ($definitions as $label => $fields) {
            try {
                new Schema($fields);
                self::fail("Expected the schema to reject: $label");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testAFieldRejectsAnInconsistentDefinition(): void
    {
        $definitions = [
            static fn () => new Field('Bad Key', FieldType::Integer, 'X'),
            static fn () => new Field('a', FieldType::Integer, ''),
            static fn () => new Field('a', FieldType::Choice, 'X'),
            static fn () => new Field('a', FieldType::Integer, 'X', min: 5, max: 1),
            static fn () => new Field('a', FieldType::Boolean, 'X', required: true, locked: true),
        ];

        foreach ($definitions as $definition) {
            try {
                $definition();
                self::fail('Expected an InvalidArgumentException.');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
