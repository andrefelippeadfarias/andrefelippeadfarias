<?php

declare(strict_types=1);

namespace Ritmo\Tests\Unit\Domain\Config;

use PHPUnit\Framework\TestCase;
use Ritmo\Domain\Config\Preset;
use Ritmo\Domain\Config\PresetLoader;
use Ritmo\Domain\Config\RuleSetValidator;
use Ritmo\Domain\Config\Schema;
use Ritmo\Domain\Model\NightType;
use Ritmo\Domain\Model\PricePlan;
use Ritmo\Domain\Model\PropertyMode;
use Ritmo\Tests\Support\Fixtures;

/**
 * The two preset files of the repository: the neutral one shipped with the plugin and the
 * parity preset generated from the reference engine's frozen configuration.
 */
final class PresetFilesTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private static function shippedDefault(): array
    {
        return json_decode((string) file_get_contents(Fixtures::pluginRoot() . '/presets/default.json'), true, 512, JSON_THROW_ON_ERROR);
    }

    public function testTheShippedDefaultPresetIsValid(): void
    {
        $preset = (new PresetLoader())->fromFile(Fixtures::pluginRoot() . '/presets/default.json');

        self::assertSame([], (new RuleSetValidator())->validate(self::shippedDefault()['rules']));
        self::assertSame([], $preset->properties, 'the neutral preset brings no property');
        self::assertSame([], $preset->rules->holidays());
        self::assertSame([], $preset->rules->ownerFloors());
    }

    public function testTheShippedDefaultPresetIsExactlyTheSchemaDefaults(): void
    {
        $file = self::shippedDefault();

        self::assertSame(Preset::FORMAT, $file['format']);
        self::assertEquals(Schema::ruleSet()->defaults(), $file['rules'], 'presets/default.json and the schema defaults went out of step');
    }

    public function testTheParityPresetPassesTheRuleSetValidator(): void
    {
        $raw = Fixtures::json('presets/parity.json');

        self::assertSame([], array_map('strval', (new RuleSetValidator())->validate($raw['rules'])));
    }

    public function testTheParityPresetLoadsWithItsPropertiesInShadowMode(): void
    {
        $preset = Fixtures::parityPreset();

        self::assertCount(2, $preset->properties);
        foreach ($preset->properties as $property) {
            self::assertSame(PropertyMode::Shadow, $property->mode);
            self::assertNotSame([], $preset->roomTypesOf($property->id));
        }
    }

    public function testTheParityPresetCarriesTheFrozenEngineConfiguration(): void
    {
        $engine = Fixtures::json('frozen/quartos.json');
        $preset = Fixtures::parityPreset();
        $rules = $preset->rules;
        $vocabulary = Fixtures::json('golden/MANIFEST.json')['vocabulary'];

        self::assertCount(count($engine['quartos']), $preset->roomTypes);
        foreach ($engine['quartos'] as $q) {
            $room = $preset->roomType($q['curto']);
            self::assertNotNull($room, $q['curto']);
            self::assertSame($vocabulary['property'][$q['pousada']], $room->propertyId);
            self::assertSame($q['unidades'], $room->units);
            self::assertSame((float) $q['base'], $room->base);
            self::assertSame((float) $q['min'], $room->min);
            self::assertSame((float) $q['max'], $room->max);
            self::assertSame((float) $q['min_referencia'], $room->referenceMin);
            self::assertSame($q['id'], $room->pricingRef);
            self::assertStringEndsWith('___' . $room->pmsRef, $q['id']);
            self::assertSame((bool) $q['banheira'], $room->hasTag('tub'));
        }

        foreach ($engine['metas'] as $window => $goal) {
            self::assertSame((float) $goal, $rules->occupancyGoals()[(int) $window]);
        }

        $pace = $engine['parametros']['pace'];
        foreach ($vocabulary['night_type'] as $engineType => $type) {
            foreach ($pace['curvas'][$engineType] as $days => $percent) {
                self::assertSame((float) $percent, $rules->paceCurve(NightType::from($type))[(int) $days], "$engineType D$days");
            }
        }
        self::assertSame((float) $pace['banda'], $rules->paceBand());
        self::assertSame((float) $pace['muito_atras'], $rules->paceVeryBehind());
        self::assertSame($pace['frente_perto_dias'], $rules->aheadNearDays());
        self::assertSame(2, $rules->groupPaceMaxUnits(), 'fixed in the engine: rooms of 1 or 2 units');
        self::assertSame(['tub'], $rules->groupPaceRoomTags());
        self::assertSame((float) $pace['k'], $rules->stepStrength());
        self::assertSame($pace['min_n'], $rules->minSampleSize());
        self::assertSame($pace['idade_max_dias'], $rules->maxSampleAgeDays());
        self::assertSame((float) $pace['tolerancia'], $rules->tolerance());
        self::assertSame((float) $pace['protecao_percentil'], $rules->protectionPercentile());

        $limits = $engine['limites_seguranca'];
        self::assertSame((float) $limits['fracao_min_referencia'], $rules->referenceMinFraction());
        self::assertSame((float) $limits['variacao_semanal_base_min'], $rules->weeklyListingChangeMax());
        self::assertSame((float) $limits['padrao_qualidade_diaria'], $rules->qualityFloor()->amount);
        self::assertSame((float) $limits['piso_feriado_real_seguranca'], $rules->holidayFloor()->amount);
        self::assertSame(
            [(float) $limits['piso_vitrine_semana_suites'], (float) $limits['piso_vitrine_fds_suites']],
            [$rules->ownerFloors()[0]->amount, $rules->ownerFloors()[1]->amount]
        );

        self::assertCount(count($engine['feriados']), $rules->holidays());
        foreach ($engine['feriados'] as $index => $holiday) {
            $loaded = $rules->holidays()[$index];
            self::assertSame($holiday['nome'], $loaded->name);
            self::assertSame([$holiday['inicio'], $holiday['fim']], [$loaded->nights->keys()[0], $loaded->nights->keys()[count($loaded->nights) - 1]]);
            self::assertSame($holiday['estadia_minima'] ?? null, $loaded->minStay);
            self::assertSame(isset($holiday['piso_real']) ? (float) $holiday['piso_real'] : null, $loaded->floor);
        }
    }

    public function testTheParityPresetConvertsThePriceFactorsOfTheEngine(): void
    {
        $engine = Fixtures::json('frozen/quartos.json');
        $rules = Fixtures::parityRules();
        $vocabulary = Fixtures::json('golden/MANIFEST.json')['vocabulary'];
        $parameters = $engine['parametros'];

        foreach ($vocabulary['property'] as $engineName => $propertyId) {
            foreach (NightType::cases() as $type) {
                self::assertSame((float) $parameters['fator_publico'][$engineName], $rules->priceFactor($propertyId, PricePlan::PublicRate, $type));
            }
            self::assertSame((float) $parameters['fator_vitrine_semana'][$engineName], $rules->priceFactor($propertyId, PricePlan::Discounted, NightType::Weekday));
            self::assertSame((float) $parameters['fator_vitrine_fds'][$engineName], $rules->priceFactor($propertyId, PricePlan::Discounted, NightType::Weekend));
        }

        // The holiday floor of each room converts with the factor the engine uses for it.
        foreach ($engine['quartos'] as $q) {
            $expected = ($q['piso_feriado_base'] ?? 'real') === 'vitrine' ? $q['fator_vitrine'] : $q['fator_real'];
            self::assertSame(
                (float) $expected,
                $rules->priceFactor($vocabulary['property'][$q['pousada']], PricePlan::Discounted, NightType::Holiday, $q['curto']),
                $q['curto']
            );
        }
    }

    public function testTheGoldenManifestMatchesTheFilesOnDisk(): void
    {
        $manifest = Fixtures::json('golden/MANIFEST.json');

        self::assertMatchesRegularExpression('/^[0-9a-f]{40}$/', $manifest['repo_commit']);
        self::assertMatchesRegularExpression('/^[0-9a-f]{40}$/', $manifest['engine_commit']);
        self::assertFalse($manifest['engine_dirty'], 'the goldens were generated from uncommitted engine code: commit the engine and run dump_golden.py again');
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $manifest['generated_at']);
        foreach ($manifest['frozen_inputs'] as $file => $sha256) {
            self::assertSame($sha256, hash_file('sha256', Fixtures::path('frozen/' . $file)), "frozen/$file changed after the goldens were generated");
        }

        $expected = [
            'pace.curva', 'pace.status_da_noite', 'pace.percentil_alvo', 'pace.pmax', 'pace.passo',
            'ancora_mercado.quantil', 'ancora_mercado.valor_percentil', 'ancora_mercado.percentil_de',
            'validar_plano.piso_pct', 'validar_plano.teto_pct', 'validar_plano.em_reais',
            'pace.efetivo', 'ancora_mercado.tipo_noite', 'builtins.round',
        ];
        $listed = array_column($manifest['files'], 'cases', 'function');
        foreach ($expected as $function) {
            self::assertArrayHasKey($function, $listed);
            self::assertGreaterThanOrEqual(24, $listed[$function], "$function has too few cases");
        }
        foreach ($manifest['files'] as $entry) {
            $golden = Fixtures::json('golden/' . $entry['file']);
            self::assertSame($entry['function'], $golden['function']);
            self::assertCount($entry['cases'], $golden['cases']);
            self::assertSame(['input', 'expected'], array_keys($golden['cases'][0]));
        }
    }
}
