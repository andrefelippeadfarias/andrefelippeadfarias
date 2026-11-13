<?php

declare(strict_types=1);

namespace Ritmo\Tests\Unit\Domain\Pricing\Ladder;

use PHPUnit\Framework\TestCase;
use Ritmo\Domain\Config\InvalidConfig;
use Ritmo\Domain\Config\Schema;
use Ritmo\Domain\Orders\OrdersConfig;
use Ritmo\Domain\Pricing\Cadence\CadenceConfig;
use Ritmo\Domain\Pricing\Ladder\LadderConfig;
use Ritmo\Domain\Pricing\Protection\ProtectionConfig;

final class LadderConfigTest extends TestCase
{
    public function testEveryAreaDescribesItsFieldsAsAConsistentSchema(): void
    {
        foreach ([LadderConfig::fields(), OrdersConfig::fields(), CadenceConfig::fields(), ProtectionConfig::fields()] as $fields) {
            $schema = new Schema($fields);
            foreach ($schema->fields() as $field) {
                self::assertNotSame('', $field->label, $field->key);
            }
        }
        self::assertTrue((new Schema(LadderConfig::fields()))->has('ladder.strategies.*.rules.*.opening.percentile'));
    }

    public function testTheNeutralDefaultsTurnTheLadderOff(): void
    {
        $config = LadderConfig::fromArray([]);

        self::assertFalse($config->enabled);
        self::assertSame([], $config->strategies);
        self::assertSame((new Schema(LadderConfig::fields()))->defaults()['ladder'], ['enabled' => false, 'strategies' => []]);
    }

    public function testAStrategyGetsTheNeutralDefaultsOfEveryField(): void
    {
        $strategy = LadderConfig::fromArray(['strategies' => [['id' => 'only-id', 'rules' => [['night_types' => ['weekend'], 'room_tags' => ['tub'], 'opening' => ['series' => 'comparable']]]]]])->strategies[0];

        self::assertFalse($strategy->enabled);
        self::assertSame('stepped', $strategy->mode);
        self::assertSame(1.0, $strategy->steps->triggerFraction, 'no step until it is configured');
        self::assertSame(5.0, $strategy->steps->percentPerUnit);
        self::assertFalse($strategy->drop->enabled);
        self::assertSame([0, 0.0, 0.0], [$strategy->drop->windowDays, $strategy->drop->occupancyBelow, $strategy->drop->percent], 'a drop that does nothing');
        self::assertSame([], $strategy->weekendFloorWeekdays);
        self::assertSame([1.0, 0.0], [$strategy->proportional->bandFactor, $strategy->proportional->bandCapDiscount], 'the discount room at its reference, not under it');
        self::assertFalse($strategy->weekendFloorOverride);
        self::assertFalse($strategy->lastMinuteFloor->enabled);
        self::assertNull($strategy->authorityOrderId);
        self::assertTrue($strategy->lockCeiling);
        self::assertSame(50.0, $strategy->rules[0]->opening?->percentile);
        self::assertNull($strategy->rules[0]->ceiling);
        self::assertFalse($strategy->rules[0]->capAtReference);
    }

    public function testTheParityFixtureLoadsTheOwnersStrategy(): void
    {
        $config = LadderConfig::fromArray(Reference::parity()['ladder']);
        $strategy = $config->activeStrategies()[0];

        self::assertCount(16, $strategy->rules, 'weekend, holiday and festive rules, each with a derived room');
        self::assertSame(7.0, $strategy->steps->percentPerUnit);
        self::assertSame(10.0, $strategy->drop->percent);
        self::assertSame(0.6, $strategy->drop->occupancyBelow);
        self::assertSame(7, $strategy->drop->windowDays);
        self::assertNull($strategy->activatedOn, 'the date of the owner order is used');
    }

    public function testDisabledStrategiesAreNotActive(): void
    {
        $data = Reference::parity()['ladder'];
        $data['strategies'][0]['enabled'] = false;

        self::assertSame([], LadderConfig::fromArray($data)->activeStrategies());
        self::assertSame([], LadderConfig::fromArray(['enabled' => false] + Reference::parity()['ladder'])->activeStrategies());
    }

    public function testEveryProblemIsReportedWithItsPath(): void
    {
        $rule = ['night_types' => ['weekend'], 'rooms' => ['A'], 'derive' => ['from_room' => 'B', 'multiplier' => 1.1]];
        $loop = ['night_types' => ['weekend'], 'rooms' => ['B'], 'derive' => ['from_room' => 'A', 'multiplier' => 1.1]];
        try {
            LadderConfig::fromArray([
                'typo' => 1,
                'strategies' => [
                    ['id' => 'a', 'valid_from' => '2026-12-01', 'valid_to' => '2026-11-01', 'rules' => [$rule, $loop, ['night_types' => ['weekend'], 'opening' => ['series' => 'x']]]],
                    ['id' => 'a', 'step' => ['trigger_fraction' => 0, 'percent_per_unit' => 80], 'mode' => 'other', 'last_minute_floor' => ['rooms' => ['A' => 9]]],
                ],
            ]);
            self::fail('expected InvalidConfig');
        } catch (InvalidConfig $error) {
            $paths = array_map(static fn ($e): string => $e->path . ' ' . $e->code, $error->errors());
        }
        foreach ([
            'ladder.typo unknown',
            'ladder.strategies.0.valid_to order',
            'ladder.strategies.0.rules reference',
            'ladder.strategies.0.rules.2.rooms required',
            'ladder.strategies.1.step.trigger_fraction range',
            'ladder.strategies.1.step.percent_per_unit range',
            'ladder.strategies.1.mode choice',
            'ladder.strategies.1.last_minute_floor.rooms.A range',
            'ladder.strategies unique',
        ] as $expected) {
            self::assertContains($expected, $paths);
        }
    }

    public function testARuleThatRepeatsARoomAndNightTypeOrOpensAboveItsCeilingIsRefusedWhenSaved(): void
    {
        $rule = ['night_types' => ['weekend'], 'rooms' => ['A'], 'opening' => ['series' => 'market', 'percentile' => 40], 'ceiling' => ['series' => 'market', 'percentile' => 75]];
        $cases = [
            'ladder.strategies.0.rules.1.rooms unique' => [$rule, ['rooms' => ['A', 'B']] + $rule],
            'ladder.strategies.0.rules.1.rooms unique ' => [$rule, ['night_types' => []] + $rule],
            'ladder.strategies.0.rules.0.opening.percentile order' => [['opening' => ['series' => 'market', 'percentile' => 90]] + $rule],
            'ladder.strategies.0.rules.0.opening.percentile order ' => [['opening' => ['series' => 'market', 'percentile' => 75, 'multiplier' => 1.2]] + $rule],
        ];
        foreach ($cases as $expected => $rules) {
            try {
                LadderConfig::fromArray(['strategies' => [['id' => 'a', 'rules' => $rules]]]);
                self::fail('expected InvalidConfig: ' . $expected);
            } catch (InvalidConfig $error) {
                self::assertContains(trim($expected), array_map(static fn ($e): string => $e->path . ' ' . $e->code, $error->errors()));
            }
        }

        $other = ['night_types' => ['weekday']] + $rule;
        $belowCeiling = ['rooms' => ['B'], 'opening' => ['series' => 'cheapest', 'percentile' => 90]] + $rule;
        self::assertCount(1, LadderConfig::fromArray(['strategies' => [['id' => 'a', 'rules' => [$rule, $other, $belowCeiling]]]])->strategies, 'another night type, or another series, is fine');
    }

    public function testTheOtherAreasHaveNeutralDefaultsAndRejectBadValues(): void
    {
        self::assertSame(7, OrdersConfig::fromArray([])->manualFreezeDays);
        $orders = OrdersConfig::fromArray([]);
        self::assertSame([], $orders->descentMarks, 'an order without its own marks does not descend');
        self::assertSame([1.0, 1.0, 0], [$orders->strongNightOccupancy, $orders->strongNightAheadOccupancy, $orders->fallbackTriggerDays], 'an order applies to every night that is not sold out');
        self::assertSame(24, CadenceConfig::fromArray([])->minHoursBetweenSteps);
        $protection = ProtectionConfig::fromArray([]);
        self::assertTrue($protection->enabled);
        self::assertSame(90.0, $protection->percentile);
        self::assertSame([], $protection->retiredFloorMarkers);
        self::assertNull($protection->lightRunMaxDays);

        foreach ([
            static fn () => OrdersConfig::fromArray(['manual_freeze_days' => 0]),
            static fn () => CadenceConfig::fromArray(['min_hours_between_steps' => 'x']),
            static fn () => ProtectionConfig::fromArray(['protection' => ['percentile' => 10]]),
            static fn () => ProtectionConfig::fromArray(['light_run_max_days' => 61]),
        ] as $build) {
            try {
                $build();
                self::fail('expected InvalidConfig');
            } catch (InvalidConfig $error) {
                self::assertNotSame([], $error->errors());
            }
        }
    }
}
