<?php

declare(strict_types=1);

namespace Ritmo\Tests\Unit\Domain\Pricing\Ladder;

use Closure;
use DateTimeImmutable;
use Ritmo\Domain\Calendar\HolidayCalendar;
use Ritmo\Domain\Config\RuleSet;
use Ritmo\Domain\Market\PriceFactor;
use Ritmo\Domain\Model\DateOverride;
use Ritmo\Domain\Model\PriceNight;
use Ritmo\Domain\Model\RoomType;
use Ritmo\Domain\Orders\OrderBook;
use Ritmo\Domain\Orders\OrderReader;
use Ritmo\Domain\Pricing\Cadence\StepLedger;
use Ritmo\Domain\Pricing\Cadence\StepRecord;
use Ritmo\Domain\Pricing\Floors;
use Ritmo\Domain\Pricing\Ladder\LadderConfig;
use Ritmo\Domain\Pricing\Ladder\LadderHardFloor;
use Ritmo\Domain\Pricing\Ladder\LadderNightEvaluator;
use Ritmo\Domain\Pricing\Ladder\LadderRelations;
use Ritmo\Domain\Pricing\Ladder\LadderStrategy;
use Ritmo\Domain\Pricing\Ladder\MarketRuler;
use Ritmo\Domain\Pricing\Ladder\RulerNight;
use Ritmo\Domain\Pricing\Ladder\SalesLadder;
use Ritmo\Domain\Support\Days;
use Ritmo\Tests\Support\Fixtures;

/**
 * The golden cases of the ladder area (tests/fixtures/golden/m2/ladder, from golden_ladder.py) and the
 * translation of the reference engine's inputs into the plugin's types, with the parity fixture of
 * the area (tests/fixtures/presets/parity-ladder.json) on top of the M1 parity preset.
 */
final class Reference
{
    /** @var array<string, mixed>|null */
    private static ?array $manifest = null;

    /** @var array<string, mixed>|null */
    private static ?array $parity = null;

    /**
     * @return list<array{name: string, input: array<string, mixed>, expected: mixed}>
     */
    public static function cases(string $function): array
    {
        return Fixtures::json('golden/m2/ladder/' . $function . '.json')['cases'];
    }

    /**
     * Data-provider rows keyed by case name.
     *
     * @return array<string, array{array<string, mixed>, mixed}>
     */
    public static function provider(string $function): array
    {
        $rows = [];
        foreach (self::cases($function) as $case) {
            $rows[$case['name']] = [$case['input'], $case['expected']];
        }

        return $rows;
    }

    /**
     * @return array<string, mixed>
     */
    public static function manifest(): array
    {
        return self::$manifest ??= Fixtures::json('golden/m2/ladder/MANIFEST.json');
    }

    /**
     * @return array<string, mixed>
     */
    public static function parity(): array
    {
        return self::$parity ??= Fixtures::json('presets/parity-ladder.json');
    }

    public static function rules(): RuleSet
    {
        return Fixtures::parityRules();
    }

    /**
     * Room types of the parity preset, by code.
     *
     * @return array<string, RoomType>
     */
    public static function rooms(): array
    {
        $rooms = [];
        foreach (Fixtures::parityPreset()->roomTypes as $room) {
            $rooms[$room->code] = $room;
        }

        return $rooms;
    }

    public static function room(string $code): RoomType
    {
        return self::rooms()[$code];
    }

    public static function strategy(): LadderStrategy
    {
        return LadderConfig::fromArray(self::parity()['ladder'])->strategies[0];
    }

    public static function ruler(): MarketRuler
    {
        return MarketRuler::fromArray(self::parity()['ruler']);
    }

    public static function ladder(?LadderStrategy $strategy = null): SalesLadder
    {
        $rules = self::rules();

        return new SalesLadder($strategy ?? self::strategy(), self::rooms(), $rules->qualityFloor(), new PriceFactor($rules), new HolidayCalendar($rules));
    }

    public static function hardFloor(): LadderHardFloor
    {
        $rules = self::rules();

        return new LadderHardFloor(new Floors($rules), new HolidayCalendar($rules), new PriceFactor($rules), $rules->holidayFloor());
    }

    public static function relations(SalesLadder $ladder): LadderRelations
    {
        return new LadderRelations($ladder, new HolidayCalendar(self::rules()), self::rules()->weekendDays());
    }

    /**
     * @param (Closure(DateTimeImmutable): ?string)|null $fallback
     */
    public static function evaluator(?Closure $fallback = null): LadderNightEvaluator
    {
        $ladder = self::ladder();

        return new LadderNightEvaluator($ladder, self::ruler(), self::hardFloor(), self::relations($ladder), $fallback);
    }

    /**
     * A ruler night written in the reference engine's format (inline variants of the cases).
     *
     * @param array<string, mixed> $night
     */
    public static function rulerNight(string $date, array $night): RulerNight
    {
        $vocabulary = self::manifest()['vocabulary'];
        $series = [];
        foreach ($vocabulary['series'] as $engine => $plugin) {
            if (isset($night[$engine])) {
                $series[$plugin] = $night[$engine];
            }
        }
        $factors = [];
        foreach ($night['fator_publico'] ?? [] as $property => $factor) {
            $factors[$vocabulary['property'][$property]] = $factor;
        }

        return RulerNight::fromArray($date, [
            'type' => $vocabulary['night_label'][$night['tipo']],
            'series' => $series,
            'public_factor' => $factors,
            'reference_sent' => $night['pedido_0911'] ?? [],
        ]);
    }

    public static function label(string $engineLabel): string
    {
        return self::manifest()['vocabulary']['night_label'][$engineLabel];
    }

    /**
     * A calendar night of the pricing system as the connector maps it: units sold from the
     * occupancy "sold/total" when given (the total may differ from the room's units), else from the
     * bookings count of a one-unit room; zero or empty floors and ceilings are unknown.
     *
     * @param array<string, mixed> $row
     */
    public static function priceNight(string $room, array $row): PriceNight
    {
        $units = self::room($room)->units;
        $occupancy = (string) ($row['mu_occupancy'] ?? $row['multi_unit_occupancy'] ?? '');
        if (str_contains($occupancy, '/')) {
            [$sold, $total] = array_map('intval', explode('/', $occupancy, 2));
        } else {
            $sold = min((int) (float) ($row['num_bookings'] ?? 0), $units);
            $total = $units;
        }
        $amount = static fn (string $key): ?float => isset($row[$key]) && $row[$key] !== '' && (float) $row[$key] > 0 ? (float) $row[$key] : null;

        return new PriceNight(Days::date($row['date']), (float) $row['price'], $amount('min_price'), $amount('max_price'), null, $sold, $total);
    }

    /**
     * The live date override of the reference format: min_price / max_price with a type
     * (percent_base by default, fixed); any other type is not understood and left unknown.
     *
     * @param array<string, mixed>|null $rule
     */
    public static function override(string $night, ?array $rule): ?DateOverride
    {
        if ($rule === null) {
            return null;
        }
        $read = static function (string $field) use ($rule): array {
            $value = $rule[$field] ?? null;
            if ($value === null || $value === '') {
                return [null, null];
            }
            $type = $rule[$field . '_type'] ?? 'percent_base';

            return match ($type) {
                'percent_base' => [(float) $value, null],
                'fixed' => [null, (float) $value],
                default => [null, null],
            };
        };
        [$minPercent, $minFixed] = $read('min_price');
        [$maxPercent, $maxFixed] = $read('max_price');

        return new DateOverride(Days::date($night), $minPercent, $maxPercent, reason: (string) ($rule['reason'] ?? ''), minFixed: $minFixed, maxFixed: $maxFixed);
    }

    /**
     * Owner orders of the reference format ({id: order}) as an order book.
     *
     * @param array<string, array<string, mixed>> $orders
     */
    public static function orders(array $orders): OrderBook
    {
        $reader = new OrderReader();

        return new OrderBook(array_values(array_map(static fn (array $o) => $reader->read(self::orderRow($o)), $orders)));
    }

    /**
     * @param array<string, mixed> $order
     *
     * @return array<string, mixed>
     */
    public static function orderRow(array $order): array
    {
        $vocabulary = self::manifest()['vocabulary'];
        $row = [];
        foreach ($order as $key => $value) {
            $name = $vocabulary['order_keys'][$key] ?? null;
            if ($name === null || $value === null) {
                continue;
            }
            $row[$name] = match ($name) {
                'type' => $vocabulary['order_type'][$value],
                'releases' => array_map(static fn (string $r): string => $vocabulary['release'][$r], $value),
                'floors', 'top', 'fallback_floors' => array_map('floatval', $value),
                'percentile' => (float) $value,
                default => $value,
            };
        }

        return $row;
    }

    /**
     * Ledger rows of the reference format as a step ledger.
     *
     * @param list<array<string, mixed>> $steps
     */
    public static function ledger(array $steps): StepLedger
    {
        return new StepLedger(array_map(static fn (array $s): StepRecord => StepRecord::fromArray(self::stepRow($s)), $steps));
    }

    /**
     * @param array<string, mixed> $step
     *
     * @return array<string, mixed>
     */
    public static function stepRow(array $step): array
    {
        $vocabulary = self::manifest()['vocabulary'];

        return [
            'room' => $step['quarto'],
            'night' => $step['noite'],
            'price_from' => $step['de'],
            'price_to' => $step['para'],
            'direction' => $vocabulary['step_direction'][$step['direcao']],
            'source_status' => $vocabulary['step_status'][$step['status']] ?? $step['status'],
            'sold' => $step['vendidas'],
            'sold_base' => $vocabulary['sold_base'][$step['base'] ?? 'quarto'],
            'sold_total' => $step['total'],
            'written_at' => $step['gravado_em'],
            'on_channel_at' => is_string($step['chegou_ao_canal']) ? $step['chegou_ao_canal'] : null,
        ];
    }

    public static function instant(string $text): DateTimeImmutable
    {
        return StepRecord::instant($text);
    }

    public static function date(string $text): DateTimeImmutable
    {
        return Days::date($text);
    }
}
