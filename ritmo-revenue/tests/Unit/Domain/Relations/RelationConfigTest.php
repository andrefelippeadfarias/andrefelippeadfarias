<?php

declare(strict_types=1);

namespace Ritmo\Tests\Unit\Domain\Relations;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Ritmo\Domain\Config\Field;
use Ritmo\Domain\Config\FieldType;
use Ritmo\Domain\Config\InvalidConfig;
use Ritmo\Domain\Config\Schema;
use Ritmo\Domain\Config\ValidationError;
use Ritmo\Domain\Model\NightType;
use Ritmo\Domain\Relations\Config\ErrorList;
use Ritmo\Domain\Relations\Config\Reader;
use Ritmo\Domain\Relations\Config\RelationFields;
use Ritmo\Domain\Relations\Config\RelationsConfig;
use Ritmo\Domain\Relations\Config\RelationSettings;
use Ritmo\Domain\Relations\Margin;
use Ritmo\Domain\Relations\Relation;
use Ritmo\Domain\Relations\RelationScope;
use Ritmo\Domain\Relations\RelationType;
use Ritmo\Domain\Relations\RoomSet;
use Ritmo\Domain\Support\Days;

final class RelationConfigTest extends TestCase
{
    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function everyType(): array
    {
        $common = ['label' => 'x', 'enabled' => true, 'priority' => 5, 'scan' => 'touched', 'strategy_nights' => 'skip', 'order_ref' => 'order-1',
            'scope' => ['night_types' => ['weekend'], 'weekdays' => [4, 5], 'periods' => [['start' => '2027-01-01', 'end' => '2027-02-28']],
                'exclude_periods' => [], 'min_days_out' => 0, 'max_days_out' => 30, 'requires_order' => null]];

        return [
            'at_least' => [RelationsSample::relationData('a', 'at_least', 'HIGH', ['reference' => 'LOW', 'margin' => ['type' => 'amount', 'value' => 10.0],
                'reference_min_units_left' => 2, 'derive' => true, 'derive_min' => 'quality_floor'], $common + ['stages' => ['strategy', 'coherence', 'audit']])],
            'between path' => [RelationsSample::relationData('b', 'between', 'MID', ['mode' => 'path', 'low_reference' => 'LOW', 'high_reference' => 'HIGH',
                'position_min' => 0.0, 'position_max' => 0.75, 'references' => 'all'], $common + ['stages' => ['coherence', 'audit']])],
            'between percent_of' => [RelationsSample::relationData('c', 'between', 'MID', ['mode' => 'percent_of', 'reference' => 'LOW',
                'min_percent' => 90.0, 'max_percent' => 110.0, 'references' => 'free_only'], $common + ['stages' => ['coherence']])],
            'premium_over_max' => [RelationsSample::relationData('d', 'premium_over_max', 'TOP', ['set' => ['room_tags' => ['tub']],
                'premium_percent' => ['weekday' => 20.0, 'weekend' => 5.0, 'holiday' => 5.0], 'epsilon_amount' => 0.5, 'members' => 'free_only'], $common + ['stages' => ['coherence']])],
            'discount_band' => [RelationsSample::relationData('e', 'discount_band', 'PLAIN', ['set' => ['rooms' => ['SPA', 'SPA2']], 'upper_percent' => 90.0,
                'lower_percent' => 85.5, 'reference_rounding_margin' => 15.0, 'lower_extra_amount' => 1.0, 'members' => 'free_only', 'on_empty_set' => 'use_sold_out',
                'down_only_night_types' => ['holiday'], 'order_floor_yields' => false, 'follow_strategy_reference' => true, 'write_mode' => 'bounds'], $common + ['stages' => ['coherence', 'validate']])],
            'occupancy_switch' => [RelationsSample::relationData('f', 'occupancy_switch', 'PLAIN', ['trigger' => ['set' => ['room_tags' => ['tub']], 'unit' => 'units', 'threshold' => 6.0],
                'then' => ['kind' => 'market_percentile', 'by_night_group' => ['weekday' => ['segment' => 'all', 'percentile' => 40.0]]],
                'cap_relation' => 'e', 'floor' => 'safety', 'suspends' => ['e']], $common + ['stages' => ['coherence', 'validate']])],
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    #[DataProvider('everyType')]
    public function testEveryTypeRoundTripsThroughItsStorageForm(array $data): void
    {
        $relation = Relation::fromArray($data);

        self::assertSame($data['type'], $relation->type->value);
        self::assertEquals($relation, Relation::fromArray($relation->toArray()));
        self::assertSame($data['params'], $relation->toArray()['params']);
    }

    public function testMissingFieldsTakeTheNeutralDefaults(): void
    {
        $relation = RelationsSample::relation('a', 'at_least', 'HIGH', ['reference' => 'LOW']);

        self::assertTrue($relation->enabled);
        self::assertSame(Relation::DEFAULT_PRIORITY, $relation->priority);
        self::assertSame(['coherence'], $relation->toArray()['stages']);
        self::assertSame('horizon', $relation->scan->value);
        self::assertSame('apply', $relation->strategyNights->value);
        self::assertSame(['type' => 'percent', 'value' => 0.0], $relation->toArray()['params']['margin']);
        self::assertNull($relation->orderRef);
    }

    /**
     * @return array<string, array{array<string, mixed>, string, string}>
     */
    public static function badRelations(): array
    {
        return [
            'unknown type' => [RelationsSample::relationData('a', 'cheaper_than', 'LOW', []), 'type', ValidationError::CHOICE],
            'missing reference' => [RelationsSample::relationData('a', 'at_least', 'LOW', []), 'params.reference', ValidationError::REQUIRED],
            'percent out of range' => [RelationsSample::relationData('a', 'premium_over_max', 'TOP', ['set' => ['room_tags' => ['tub']], 'premium_percent' => ['weekday' => 900]]), 'params.premium_percent.weekday', ValidationError::RANGE],
            'way inverted' => [RelationsSample::relationData('a', 'between', 'MID', ['low_reference' => 'LOW', 'high_reference' => 'HIGH', 'position_min' => 0.8, 'position_max' => 0.5]), 'params.position_max', ValidationError::ORDER],
            'band inverted' => [RelationsSample::relationData('a', 'discount_band', 'PLAIN', ['set' => ['room_tags' => ['tub']], 'upper_percent' => 80, 'lower_percent' => 90]), 'params.lower_percent', ValidationError::ORDER],
            'percents inverted' => [RelationsSample::relationData('a', 'between', 'MID', ['mode' => 'percent_of', 'reference' => 'LOW', 'min_percent' => 120, 'max_percent' => 100]), 'params.max_percent', ValidationError::ORDER],
            'same two references' => [RelationsSample::relationData('a', 'between', 'MID', ['low_reference' => 'LOW', 'high_reference' => 'LOW']), 'params.high_reference', ValidationError::REFERENCE],
            'set with rooms and tags' => [RelationsSample::relationData('a', 'premium_over_max', 'TOP', ['set' => ['rooms' => ['LOW'], 'room_tags' => ['tub']]]), 'params.set.rooms', ValidationError::REQUIRED],
            'band in the strategy stage' => [RelationsSample::relationData('a', 'discount_band', 'PLAIN', ['set' => ['room_tags' => ['tub']], 'upper_percent' => 90, 'lower_percent' => 85], ['stages' => ['strategy']]), 'stages', ValidationError::CHOICE],
            'plain at_least in the audit' => [RelationsSample::relationData('a', 'at_least', 'HIGH', ['reference' => 'LOW'], ['stages' => ['audit']]), 'stages', ValidationError::CHOICE],
            'bad id' => [RelationsSample::relationData('Not An Id', 'at_least', 'HIGH', ['reference' => 'LOW']), 'id', ValidationError::FORMAT],
            'unknown field' => [RelationsSample::relationData('a', 'at_least', 'HIGH', ['reference' => 'LOW'], ['price_plan' => 'public']), 'price_plan', ValidationError::UNKNOWN],
            'switch without action' => [RelationsSample::relationData('a', 'occupancy_switch', 'PLAIN', ['trigger' => ['set' => ['room_tags' => ['tub']], 'threshold' => 80]]), 'params.then.kind', ValidationError::REQUIRED],
            'scope days inverted' => [RelationsSample::relationData('a', 'at_least', 'HIGH', ['reference' => 'LOW'], ['scope' => ['min_days_out' => 9, 'max_days_out' => 3]]), 'scope.max_days_out', ValidationError::ORDER],
            'scope period inverted' => [RelationsSample::relationData('a', 'at_least', 'HIGH', ['reference' => 'LOW'], ['scope' => ['periods' => [['start' => '2027-02-01', 'end' => '2027-01-01']]]]), 'scope.periods.0.end', ValidationError::ORDER],
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    #[DataProvider('badRelations')]
    public function testInvalidRelationsAreRefusedWithThePathOfTheValue(array $data, string $path, string $code): void
    {
        try {
            Relation::fromArray($data);
            self::fail('The relation should have been refused.');
        } catch (InvalidConfig $refused) {
            $found = array_map(static fn (ValidationError $error): string => $error->path . ' ' . $error->code, $refused->errors());
            self::assertContains($path . ' ' . $code, $found);
        }
    }

    public function testTheNeutralConfigurationHasNoRelationAndTheDefaultsOfTheFields(): void
    {
        $neutral = RelationsConfig::neutral();
        $schema = new Schema(RelationFields::general());

        self::assertSame([], $neutral->relations);
        self::assertSame([], $neutral->priceBands);
        self::assertSame($schema->defaults()['relations'], $neutral->settings->toArray());
        self::assertEquals($neutral, RelationsConfig::fromArray([]));
        self::assertEquals($neutral, RelationsConfig::fromArray($neutral->toArray()));
    }

    public function testEveryFieldListIsAConsistentTreeWithValidChoices(): void
    {
        $lists = [RelationFields::general(), RelationFields::relation(), RelationFields::priceBand()];
        foreach (RelationType::cases() as $type) {
            $lists[] = RelationFields::params($type);
        }
        foreach ($lists as $fields) {
            $schema = new Schema($fields);
            foreach ($schema->fields() as $field) {
                self::assertNotSame('', $field->label);
                if ($field->type === FieldType::Choice && !$field->required) {
                    self::assertContains($field->default, $field->enum ?? [], $field->key);
                }
            }
        }
        self::assertSame(Relation::DEFAULT_PRIORITY, (new Schema(RelationFields::relation()))->field('priority')->default);
        self::assertSame(Field::UNIT_MONEY, (new Schema(RelationFields::general()))->field('relations.tolerance.trigger_amount')->unit);
    }

    public function testRepeatedIdsAreRefused(): void
    {
        $item = RelationsSample::relationData('same', 'at_least', 'HIGH', ['reference' => 'LOW']);

        $this->expectException(InvalidConfig::class);
        RelationsConfig::fromArray(['relations' => ['items' => [$item, $item]]]);
    }

    public function testSettingsReadTheirGroupsAndGiveTheAuditTolerance(): void
    {
        $config = RelationsConfig::fromArray(['relations' => ['tolerance' => ['audit_base_percent' => 2.0, 'audit_amount' => 3.0], 'blocked_nights' => 'ignore']]);

        self::assertSame('ignore', $config->settings->blockedNights->value);
        self::assertSame(2.0 / 100 * 1173.0 + 3.0, $config->settings->auditTolerance(RelationsSample::rooms()[0]));
        self::assertEqualsWithDelta(12.73, (new RelationSettings())->auditTolerance(RelationsSample::rooms()[0]), 1e-9, '1% of the base + 1');
    }

    public function testRoomSetsResolveTagsInRoomOrderWithoutTheTarget(): void
    {
        $rooms = RelationsSample::rooms();

        self::assertSame(['LOW', 'HIGH', 'MID'], RoomSet::ofTags(['tub'])->resolve('TOP', 'inn', $rooms));
        self::assertSame(['SPA', 'SPA2'], RoomSet::ofTags(['tub'])->resolve('PLAIN', 'lodge', $rooms));
        self::assertSame(['SPA'], RoomSet::ofRooms(['SPA', 'PLAIN'])->resolve('PLAIN', 'lodge', $rooms));
        self::assertSame([], RoomSet::ofTags(['view'])->resolve('PLAIN', 'lodge', $rooms));
    }

    public function testScopeMatchesByNightTypeWeekdayPeriodAndLeadTime(): void
    {
        $today = Days::date('2027-03-01');
        $friday = Days::date('2027-03-05');
        $scope = RelationScope::read(new Reader([
            'night_types' => ['weekend'], 'weekdays' => [4], 'periods' => [['start' => '2027-03-01', 'end' => '2027-03-31']],
            'exclude_periods' => [['start' => '2027-03-12', 'end' => '2027-03-12']], 'min_days_out' => 2, 'max_days_out' => 20,
        ], '', new ErrorList()));

        self::assertTrue($scope->matches($friday, NightType::Weekend, $today));
        self::assertFalse($scope->matches($friday, NightType::Weekday, $today), 'night type');
        self::assertFalse($scope->matches(Days::date('2027-03-06'), NightType::Weekend, $today), 'Saturday is not listed');
        self::assertFalse($scope->matches(Days::date('2027-03-12'), NightType::Weekend, $today), 'excluded period');
        self::assertFalse($scope->matches(Days::date('2027-04-02'), NightType::Weekend, $today), 'outside the period');
        self::assertFalse($scope->matches($friday, NightType::Weekend, Days::date('2027-03-04')), 'one day out is below the minimum');
        self::assertFalse($scope->matches(Days::date('2027-03-26'), NightType::Weekend, $today), '25 days out is beyond the maximum');
        self::assertTrue((new RelationScope())->matches($friday, NightType::Holiday, $today), 'an empty scope matches every night');
    }

    public function testMarginsApplyAPercentOrAnAmount(): void
    {
        self::assertSame(1000 * (1 + 15 / 100), Margin::percent(15.0)->applyTo(1000.0));
        self::assertSame(1010.0, Margin::amount(10.0)->applyTo(1000.0));
        self::assertSame(1000.0, Margin::none()->applyTo(1000.0));
    }
}
