<?php

declare(strict_types=1);

namespace Ritmo\Tests\Unit\Domain\Config;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Ritmo\Domain\Config\InvalidConfig;
use Ritmo\Domain\Config\Preset;
use Ritmo\Domain\Config\PresetLoader;
use Ritmo\Domain\Config\RuleSet;
use Ritmo\Domain\Config\Schema;
use Ritmo\Domain\Config\ValidationError;
use Ritmo\Domain\Model\PropertyMode;

final class PresetLoaderTest extends TestCase
{
    /**
     * A small complete preset: one property with two rooms and rules that reference them.
     *
     * @return array<string, mixed>
     */
    private static function preset(): array
    {
        $rules = Schema::ruleSet()->defaults();
        $rules['price_factors']['properties']['seaside'] = $rules['price_factors']['properties']['*'];
        $rules['price_factors']['room_overrides'][] = ['room' => 'Loft', 'price_plan' => 'public', 'night_type' => 'weekend', 'factor' => 0.9];

        return [
            'format' => Preset::FORMAT,
            'format_version' => Preset::FORMAT_VERSION,
            'name' => 'Seaside',
            'description' => 'Test preset.',
            'properties' => [[
                'id' => 'seaside',
                'name' => 'Seaside Inn',
                'timezone' => 'Europe/Lisbon',
                'currency' => 'EUR',
                'mode' => 'approval',
                'tier' => 'premium',
                'occupancy_goals' => ['7' => 80, '30' => 45],
                'room_types' => [
                    ['code' => 'Loft', 'name' => 'Loft', 'units' => 2, 'pms_ref' => '11', 'pricing_ref' => 'L-11', 'base' => 800, 'min' => 500, 'max' => 3000, 'reference_min' => 450, 'tags' => ['tub']],
                    ['code' => 'Twin', 'name' => 'Twin', 'units' => 4, 'base' => 600.5, 'min' => 400, 'max' => 3000, 'reference_min' => 380],
                ],
            ]],
            'rules' => $rules,
        ];
    }

    public function testLoadsPropertiesRoomTypesAndRules(): void
    {
        $preset = (new PresetLoader())->fromArray(self::preset());

        self::assertSame('Seaside', $preset->name);
        self::assertSame('Test preset.', $preset->description);
        self::assertCount(1, $preset->properties);
        self::assertCount(2, $preset->roomTypes);

        $property = $preset->property('seaside');
        self::assertNotNull($property);
        self::assertSame(PropertyMode::Approval, $property->mode);
        self::assertSame('premium', $property->tier);
        self::assertSame([7 => 80.0, 30 => 45.0], $property->occupancyGoals);

        $loft = $preset->roomType('Loft');
        self::assertNotNull($loft);
        self::assertSame('seaside', $loft->propertyId);
        self::assertSame('11', $loft->pmsRef);
        self::assertSame(['tub'], $loft->tags);
        $twin = $preset->roomType('Twin');
        self::assertSame('', $twin?->pmsRef, 'a room can be loaded before it is mapped');
        self::assertSame(600.5, $twin?->base);
        self::assertSame([$loft, $twin], $preset->roomTypesOf('seaside'));
        self::assertSame([], $preset->roomTypesOf('elsewhere'));
        self::assertNull($preset->property('elsewhere'));
        self::assertNull($preset->roomType('Suite'));
        self::assertInstanceOf(RuleSet::class, $preset->rules);
    }

    public function testExportAndImportRoundTrip(): void
    {
        $loader = new PresetLoader();
        $first = $loader->fromArray(self::preset());
        $json = json_encode($first->toArray(), JSON_THROW_ON_ERROR);
        $second = $loader->fromJson($json);

        self::assertEquals($first->toArray(), $second->toArray());
        self::assertSame($first->rules->hash(), $second->rules->hash());
        self::assertEquals($first->properties, $second->properties);
        self::assertEquals($first->roomTypes, $second->roomTypes);
    }

    public function testRulesMissingFromAnOlderPresetGetTheirDefaults(): void
    {
        $data = self::preset();
        unset($data['rules']['pace']['tolerance'], $data['rules']['safety'], $data['rules']['pace']['curves']['weekend'][14]);

        $rules = (new PresetLoader())->fromArray($data)->rules;
        $defaults = RuleSet::defaults();

        self::assertSame($defaults->tolerance(), $rules->tolerance());
        self::assertSame($defaults->weeklyListingChangeMax(), $rules->weeklyListingChangeMax());
        self::assertSame($defaults->get('pace.curves.weekend.14'), $rules->get('pace.curves.weekend.14'));
    }

    public function testAPresetWithoutPropertiesIsValid(): void
    {
        $data = self::preset();
        $data['properties'] = [];
        $data['rules'] = Schema::ruleSet()->defaults();

        $preset = (new PresetLoader())->fromArray($data);

        self::assertSame([], $preset->properties);
        self::assertSame([], $preset->roomTypes);
    }

    /**
     * @return array<string, array{callable(array<string, mixed>): array<string, mixed>, string, string}>
     */
    public static function brokenPresets(): array
    {
        return [
            'wrong format' => [static fn (array $d) => ['format' => 'something-else'] + $d, 'format', ValidationError::FORMAT],
            'future version' => [static fn (array $d) => ['format_version' => 2] + $d, 'format_version', ValidationError::FORMAT],
            'unknown top-level key' => [static fn (array $d) => $d + ['extra' => 1], 'extra', ValidationError::UNKNOWN],
            'name is not text' => [static fn (array $d) => ['name' => ['x']] + $d, 'name', ValidationError::TYPE],
            'properties is not a list' => [static fn (array $d) => ['properties' => 'none'] + $d, 'properties', ValidationError::TYPE],
            'no rules' => [static fn (array $d) => array_diff_key($d, ['rules' => 1]), 'rules', ValidationError::REQUIRED],
            'invalid rule' => [static fn (array $d) => self::set($d, ['rules', 'pace', 'band'], -1), 'rules.pace.band', ValidationError::RANGE],
            'unknown rule' => [static fn (array $d) => self::set($d, ['rules', 'pace', 'bandd'], 1), 'rules.pace.bandd', ValidationError::UNKNOWN],
            'property is not a group' => [static fn (array $d) => self::set($d, ['properties', 0], 'seaside'), 'properties.0', ValidationError::TYPE],
            'unknown property key' => [static fn (array $d) => self::set($d, ['properties', 0, 'colour'], 'blue'), 'properties.0.colour', ValidationError::UNKNOWN],
            'invalid timezone' => [static fn (array $d) => self::set($d, ['properties', 0, 'timezone'], 'Atlantis/Central'), 'properties.0', ValidationError::FORMAT],
            'invalid mode' => [static fn (array $d) => self::set($d, ['properties', 0, 'mode'], 'turbo'), 'properties.0', ValidationError::FORMAT],
            'missing currency' => [static fn (array $d) => self::set($d, ['properties', 0, 'currency'], null), 'properties.0', ValidationError::FORMAT],
            'tier that does not exist' => [static fn (array $d) => self::set($d, ['properties', 0, 'tier'], 'deluxe'), 'properties.0.tier', ValidationError::REFERENCE],
            'rooms are not a list' => [static fn (array $d) => self::set($d, ['properties', 0, 'room_types'], ['a' => 1]), 'properties.0.room_types', ValidationError::TYPE],
            'room is not a group' => [static fn (array $d) => self::set($d, ['properties', 0, 'room_types', 1], 'Twin'), 'properties.0.room_types.1', ValidationError::TYPE],
            'unknown room key' => [static fn (array $d) => self::set($d, ['properties', 0, 'room_types', 0, 'beds'], 2), 'properties.0.room_types.0.beds', ValidationError::UNKNOWN],
            'room without units' => [static fn (array $d) => self::set($d, ['properties', 0, 'room_types', 0, 'units'], 0), 'properties.0.room_types.0', ValidationError::FORMAT],
            'room with max below min' => [static fn (array $d) => self::set($d, ['properties', 0, 'room_types', 1, 'max'], 100), 'properties.0.room_types.1', ValidationError::FORMAT],
            'repeated room code' => [static fn (array $d) => self::set($d, ['properties', 0, 'room_types', 1, 'code'], 'Loft'), 'properties.0.room_types', ValidationError::UNIQUE],
            'factor table of an unknown property' => [
                static fn (array $d) => self::set($d, ['rules', 'price_factors', 'properties', 'hillside'], $d['rules']['price_factors']['properties']['*']),
                'rules.price_factors.properties.hillside',
                ValidationError::REFERENCE,
            ],
            'exception for an unknown room' => [
                static fn (array $d) => self::set($d, ['rules', 'price_factors', 'room_overrides', 0, 'room'], 'Suite'),
                'rules.price_factors.room_overrides.0.room',
                ValidationError::REFERENCE,
            ],
        ];
    }

    /**
     * @param callable(array<string, mixed>): array<string, mixed> $break
     */
    #[DataProvider('brokenPresets')]
    public function testABrokenPresetIsRejectedWithThePathOfTheProblem(callable $break, string $path, string $code): void
    {
        try {
            (new PresetLoader())->fromArray($break(self::preset()));
            self::fail('Expected InvalidConfig.');
        } catch (InvalidConfig $e) {
            $found = array_map(static fn (ValidationError $error): string => $error->path . ' [' . $error->code . ']', $e->errors());
            self::assertContains($path . ' [' . $code . ']', $found, implode('; ', array_map('strval', $e->errors())));
        }
    }

    public function testARepeatedPropertyIdIsRejected(): void
    {
        $data = self::preset();
        $data['properties'][] = ['id' => 'seaside', 'name' => 'Twin of Seaside', 'timezone' => 'UTC', 'currency' => 'EUR'];

        try {
            (new PresetLoader())->fromArray($data);
            self::fail('Expected InvalidConfig.');
        } catch (InvalidConfig $e) {
            self::assertSame('properties.1.id', $e->errors()[0]->path);
            self::assertSame(ValidationError::UNIQUE, $e->errors()[0]->code);
        }
    }

    public function testTextThatIsNotAJsonObjectIsRejected(): void
    {
        foreach (['', 'not json', '"just text"', '42', '{"format": '] as $json) {
            try {
                (new PresetLoader())->fromJson($json);
                self::fail('Expected InvalidConfig for: ' . $json);
            } catch (InvalidConfig $e) {
                self::assertSame(ValidationError::FORMAT, $e->errors()[0]->code);
            }
        }
    }

    public function testAFileThatCannotBeReadIsRejected(): void
    {
        $this->expectException(InvalidConfig::class);
        (new PresetLoader())->fromFile(__DIR__ . '/no-such-preset.json');
    }

    public function testInvalidConfigSummarisesManyErrors(): void
    {
        $errors = [];
        for ($i = 1; $i <= 8; $i++) {
            $errors[] = new ValidationError('field.' . $i, ValidationError::RANGE, 'is wrong');
        }

        $exception = new InvalidConfig('preset', $errors);

        self::assertCount(8, $exception->errors());
        self::assertStringContainsString('field.1: is wrong', $exception->getMessage());
        self::assertStringContainsString('field.5: is wrong', $exception->getMessage());
        self::assertStringNotContainsString('field.6', $exception->getMessage());
        self::assertStringContainsString('(and 3 more)', $exception->getMessage());
    }

    /**
     * @param array<int|string, mixed> $data
     * @param list<int|string>         $path
     *
     * @return array<int|string, mixed>
     */
    private static function set(array $data, array $path, mixed $value): array
    {
        $node = &$data;
        foreach ($path as $segment) {
            $node = &$node[$segment];
        }
        $node = $value;

        return $data;
    }
}
