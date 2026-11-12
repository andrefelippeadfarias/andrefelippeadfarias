<?php

declare(strict_types=1);

namespace Ritmo\Tests\Unit\Domain\Config;

use PHPUnit\Framework\TestCase;
use Ritmo\Domain\Config\FieldType;
use Ritmo\Domain\Config\InvalidConfig;
use Ritmo\Domain\Config\PresetLoader;
use Ritmo\Domain\Config\RuleReferences;
use Ritmo\Domain\Config\RuleSet;
use Ritmo\Domain\Config\RuleSetValidator;
use Ritmo\Domain\Config\Schema;
use Ritmo\Domain\Config\ValidationError;
use Ritmo\Domain\Pricing\Cadence\NoEffectConfig;
use Ritmo\Tests\Support\Fixtures;

/**
 * The sections of milestone M2 in the one rule set schema: neutral defaults, typed accessors,
 * the checks their modules own (run by the rule set validator) and the references of a preset.
 */
final class M2SectionsTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private static function parityRules(): array
    {
        return Fixtures::json('presets/parity.json')['rules'];
    }

    public function testTheNeutralDefaultsSwitchEveryM2RuleOff(): void
    {
        $rules = RuleSet::defaults();

        self::assertSame([], $rules->relations()->relations);
        self::assertSame([], $rules->relations()->priceBands);
        self::assertFalse($rules->ladder()->enabled);
        self::assertSame([], $rules->ladder()->activeStrategies());
        self::assertFalse($rules->stay()->arrival->enabled);
        self::assertSame([], $rules->stay()->closedDays);
        self::assertSame(7, $rules->orders()->manualFreezeDays);
        self::assertSame(24, $rules->cadence()->minHoursBetweenSteps);
        self::assertTrue($rules->noEffect()->dropRoundingOnly, 'the rounding-only filter is on outside the parity preset');
        self::assertFalse($rules->plan()->lastMinute->enabled);
        self::assertSame(70, $rules->plan()->sliceMaxDates);
    }

    public function testTheParityPresetCarriesTheReferenceEnginesM2Rules(): void
    {
        $rules = Fixtures::parityRules();

        self::assertCount(6, $rules->relations()->relations);
        self::assertCount(1, $rules->relations()->priceBands);
        self::assertTrue($rules->ladder()->enabled);
        self::assertCount(1, $rules->ladder()->activeStrategies());
        self::assertTrue($rules->stay()->arrival->enabled);
        self::assertCount(1, $rules->stay()->closedDays);
        self::assertFalse($rules->noEffect()->dropRoundingOnly, 'parity: the reference has no rounding-only filter');
        self::assertSame(90.0, $rules->soldOut()->percentile);
        self::assertSame(7, $rules->plan()->maxMinStay);
        self::assertSame($rules->relations(), $rules->relations(), 'a section is read once');
    }

    public function testTheModulesOwnChecksJoinTheRuleSetValidator(): void
    {
        $data = self::parityRules();
        $data['relations']['items'][1]['params']['position_min'] = 0.9;
        $data['relations']['items'][1]['params']['position_max'] = 0.5;
        $data['ladder']['strategies'][0]['valid_to'] = '2020-01-01';

        $errors = array_map('strval', (new RuleSetValidator())->validate($data));

        self::assertContains('relations.items.1.params.position_max: must not be below the lowest point of the way', $errors);
        self::assertContains('ladder.strategies.0.valid_to: must not be before the first night', $errors);
    }

    public function testRelationParametersAreAVariantGroup(): void
    {
        $field = Schema::ruleSet()->field('relations.items.*.params');
        self::assertSame(FieldType::Variant, $field->type);

        $data = self::parityRules();
        $data['relations']['items'][0]['params'] = [1, 2];
        $errors = (new RuleSetValidator())->validate($data);
        self::assertSame(['relations.items.0.params'], array_map(static fn (ValidationError $e): string => $e->path, $errors));
        self::assertSame(ValidationError::TYPE, $errors[0]->code);

        $data = self::parityRules();
        $data['relations']['items'][0]['params'] = array_reverse($data['relations']['items'][0]['params'], true);
        self::assertSame(
            RuleSet::fromArray(self::parityRules())->hash(),
            RuleSet::fromArray($data)->hash(),
            'the order of the keys of a variant group does not change the version'
        );
    }

    public function testASectionCanBeCheckedAlone(): void
    {
        $section = Schema::ruleSet()->defaults()['stay'];
        self::assertSame([], (new RuleSetValidator())->validateSection('stay', $section));

        $section['holiday_min_stay'] = 0;
        $errors = (new RuleSetValidator())->validateSection('stay', $section);
        self::assertSame(['stay.holiday_min_stay'], array_map(static fn (ValidationError $e): string => $e->path, $errors));
    }

    public function testTheNoEffectSectionReadsItsSettings(): void
    {
        $config = NoEffectConfig::fromArray(['drop_rounding_only' => false, 'money_slack' => 2]);

        self::assertFalse($config->dropRoundingOnly);
        self::assertSame(2.0, $config->moneySlack);

        $this->expectException(InvalidConfig::class);
        NoEffectConfig::fromArray(['money_slack' => -1]);
    }

    public function testEveryRoomOrPropertyNamedByTheRulesMustExist(): void
    {
        $rules = self::parityRules();
        $rules['stay']['open_days'][0]['rooms'][] = 'Ghost';
        $rules['stay']['pricing_sync']['min_stay_rooms'] = ['*'];
        $rules['stay']['arrival_rule']['properties'] = ['nowhere' => ['0' => 2]];
        $rules['plan']['validator']['no_discount_rooms'] = ['Phantom'];

        $errors = array_map('strval', RuleReferences::unknown($rules, ['A', 'B'], ['main']));

        self::assertContains('stay.open_days.0.rooms.1: "Ghost" is not a room type of this preset', $errors);
        self::assertContains('stay.arrival_rule.properties.nowhere: "nowhere" is not a property of this preset', $errors);
        self::assertContains('plan.validator.no_discount_rooms.0: "Phantom" is not a room type of this preset', $errors);
        self::assertNotContains('stay.pricing_sync.min_stay_rooms.0: "*" is not a room type of this preset', $errors, '"*" means every room');
    }

    public function testThePresetLoaderRefusesBrokenReferencesAndRelations(): void
    {
        $preset = Fixtures::json('presets/parity.json');
        $preset['rules']['relations']['items'][0]['target'] = 'Ghost';
        $preset['rules']['stay']['open_days'][0]['rooms'][] = 'Nope';

        try {
            (new PresetLoader())->fromArray($preset);
            self::fail('the preset should be refused');
        } catch (InvalidConfig $invalid) {
            $paths = array_map(static fn (ValidationError $e): string => $e->path, $invalid->errors());
            self::assertContains('rules.relations.items.0.target', $paths);
            self::assertContains('rules.stay.open_days.0.rooms.1', $paths);
        }
    }
}
