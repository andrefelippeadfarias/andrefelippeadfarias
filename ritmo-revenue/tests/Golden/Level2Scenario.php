<?php

declare(strict_types=1);

namespace Ritmo\Tests\Golden;

use DateTimeImmutable;
use Ritmo\Domain\Market\AnchorLookup;
use Ritmo\Domain\Model\AnchorOrigin;
use Ritmo\Domain\Model\DateOverride;
use Ritmo\Domain\Model\MarketAnchor;
use Ritmo\Domain\Model\PriceNight;
use Ritmo\Domain\Model\Property;
use Ritmo\Domain\Model\RoomType;
use Ritmo\Domain\Orders\OrderBook;
use Ritmo\Domain\Orders\OrderReader;
use Ritmo\Domain\Plan\Assembly\RunInputs;
use Ritmo\Domain\Plan\PlanItem;
use Ritmo\Domain\Pricing\Ladder\MarketRuler;
use Ritmo\Domain\Support\Days;
use Ritmo\Tests\Support\Fixtures;
use Ritmo\Tests\Unit\Domain\Plan\Golden\PlanParity;
use Ritmo\Tests\Unit\Domain\Pricing\Ladder\Reference;

/**
 * One level-2 scenario of tests/fixtures/golden/level2: the frozen inputs of a whole run of the
 * reference engine (calendars, date rules in force, step ledger, owner orders, nights blocked in
 * the PMS, light-run filter) turned into the RunInputs of each property of the parity preset.
 */
final class Level2Scenario
{
    /** @var array<string, mixed> */
    public readonly array $frozen;

    public readonly DateTimeImmutable $now;

    /**
     * @param array<string, mixed> $case a case of pace.plano.json
     */
    public function __construct(public readonly array $case)
    {
        $this->frozen = Fixtures::json('golden/level2/frozen/' . $case['input']['frozen']);
        $this->now = new DateTimeImmutable($this->frozen['now']);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function cases(): array
    {
        return Fixtures::json('golden/level2/pace.plano.json')['cases'];
    }

    public function today(): DateTimeImmutable
    {
        return Days::date($this->frozen['today']);
    }

    /**
     * @return list<RoomType>
     */
    public function rooms(Property $property): array
    {
        return Fixtures::parityPreset()->roomTypesOf($property->id);
    }

    public function inputs(Property $property): RunInputs
    {
        $rooms = $this->rooms($property);
        $codes = array_map(static fn (RoomType $r): string => $r->code, $rooms);
        $calendar = $overrides = [];
        foreach ($codes as $code) {
            foreach ($this->frozen['calendars'][$code] ?? [] as $night => $row) {
                $calendar[$code][$night] = self::priceNight($night, $row);
            }
            foreach ($this->frozen['rules'][$code] ?? [] as $night => $rule) {
                $overrides[$code][$night] = self::override($night, $rule);
            }
        }
        $readAt = array_fill_keys($codes, $this->now->modify('-1 hour'));

        return new RunInputs(
            $property,
            $rooms,
            $this->today(),
            $this->now,
            (int) $this->frozen['days'],
            $calendar,
            $overrides,
            self::anchors(),
            Reference::ledger($this->frozen['ledger']['passos']),
            self::orders($this->frozen['orders']),
            MarketRuler::fromArray(Reference::parity()['ruler']),
            $this->keys($this->frozen['blocked'], $codes),
            $this->filter($codes),
            $readAt,
            Fixtures::json('presets/parity-plan.json')['weekly_reference'],
        );
    }

    /**
     * The expected entries ("items", "deletions") of the property's rooms, in the reference's order.
     *
     * @return list<array<string, mixed>>
     */
    public function expected(string $list, Property $property): array
    {
        $codes = array_map(static fn (RoomType $r): string => $r->code, $this->rooms($property));

        return array_values(array_filter($this->case['expected'][$list], static fn (array $it): bool => in_array($it['quarto'], $codes, true)));
    }

    /**
     * @param list<array<string, mixed>> $orders owner orders in the reference's format
     */
    private static function orders(array $orders): OrderBook
    {
        $reader = new OrderReader();

        return new OrderBook(array_map(static fn (array $o) => $reader->read(PlanParity::orderRow($o)), $orders));
    }

    private static function anchors(): AnchorLookup
    {
        $anchors = [];
        foreach (Fixtures::json('frozen/ancora_mercado.json')['noites'] as $night => $row) {
            $anchors[$night] = new MarketAnchor(
                Days::date($night),
                (float) $row['p25'],
                (float) $row['p50'],
                (float) $row['p75'],
                (float) $row['p90'],
                (float) $row['max'],
                (int) ($row['n'] ?? 0),
                isset($row['busca_em']) ? Days::date($row['busca_em']) : null,
                AnchorOrigin::Measured,
            );
        }

        return new AnchorLookup(Fixtures::parityRules(), $anchors);
    }

    /**
     * @param array<string, mixed> $row a recalculation row: price, "sold/total", floor and ceiling shown
     */
    private static function priceNight(string $night, array $row): PriceNight
    {
        [$sold, $total] = array_map('intval', explode('/', (string) $row['mu_occupancy']));

        return new PriceNight(Days::date($night), (float) $row['price'], (float) $row['min_price'], (float) $row['max_price'], null, $sold, $total);
    }

    /**
     * @param array<string, mixed> $rule a date rule as get_listing_date_overrides gives it
     */
    private static function override(string $night, array $rule): DateOverride
    {
        $amount = static function (string $field) use ($rule): array {
            if (!isset($rule[$field])) {
                return [null, null];
            }

            return ($rule[$field . '_type'] ?? 'percent_base') === 'fixed' ? [null, (float) $rule[$field]] : [(float) $rule[$field], null];
        };
        [$minPercent, $minFixed] = $amount('min_price');
        [$maxPercent, $maxFixed] = $amount('max_price');

        return new DateOverride(
            Days::date($night),
            $minPercent,
            $maxPercent,
            isset($rule['min_stay']) ? (int) $rule['min_stay'] : null,
            $rule['check_in'] ?? null,
            $rule['check_out'] ?? null,
            (string) ($rule['reason'] ?? ''),
            minFixed: $minFixed,
            maxFixed: $maxFixed,
        );
    }

    /**
     * @param list<array{0: string, 1: string}> $pairs
     * @param list<string>                      $codes
     *
     * @return array<string, true>
     */
    private function keys(array $pairs, array $codes): array
    {
        $out = [];
        foreach ($pairs as [$room, $night]) {
            if (in_array($room, $codes, true)) {
                $out[PlanItem::keyOf($room, Days::date($night))] = true;
            }
        }

        return $out;
    }

    /**
     * The light-run filter ("ROOM=YYYY-MM-DD" or "ROOM=YYYY-MM-DD..YYYY-MM-DD"), or null for a full run.
     *
     * @param list<string> $codes
     *
     * @return array<string, true>|null
     */
    private function filter(array $codes): ?array
    {
        if ($this->frozen['filter'] === null) {
            return null;
        }
        $pairs = [];
        foreach ($this->frozen['filter'] as $entry) {
            [$room, $range] = explode('=', $entry, 2);
            [$from, $to] = str_contains($range, '..') ? explode('..', $range, 2) : [$range, $range];
            for ($night = Days::date($from); $night <= Days::date($to); $night = Days::add($night, 1)) {
                $pairs[] = [$room, Days::key($night)];
            }
        }

        return $this->keys($pairs, $codes);
    }
}
