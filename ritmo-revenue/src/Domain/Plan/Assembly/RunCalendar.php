<?php

declare(strict_types=1);

namespace Ritmo\Domain\Plan\Assembly;

use DateTimeImmutable;
use Ritmo\Domain\Config\RuleSet;
use Ritmo\Domain\Model\PriceNight;
use Ritmo\Domain\Model\RoomType;
use Ritmo\Domain\Pricing\PaceBasis;
use Ritmo\Domain\Pricing\Protection\LiveBounds;
use Ritmo\Domain\Pricing\RuleInForce;
use Ritmo\Domain\Support\Days;

/**
 * The calendar of a run as the engine reads it: each price brought within the floor and ceiling
 * of the date rule in force (an old recalculation does not see what was written after it), the
 * floor and ceiling in force, the units sold and the pace basis of small rooms.
 *
 * Reference: pace.preco_no_vigente, pace.vigente, comum.vendidas_total, pace.ocupacao_pousada.
 */
final class RunCalendar
{
    /** @var array<string, array<string, PriceNight>> */
    private array $rows = [];

    /** @var array<string, float> room-night key => the price read, when the rule in force moved it */
    private array $read = [];

    private readonly PaceBasis $basis;

    public function __construct(private readonly RunInputs $inputs, RuleSet $rules)
    {
        $this->basis = new PaceBasis($rules);
        foreach ($inputs->calendar as $code => $nights) {
            $room = $inputs->rooms[$code] ?? null;
            if ($room === null) {
                continue;
            }
            foreach ($nights as $key => $night) {
                $this->rows[$code][$key] = $this->withinRule($room, $night, $inputs->overrides[$code][$key] ?? null, (string) $key);
            }
        }
    }

    public function inputs(): RunInputs
    {
        return $this->inputs;
    }

    public function row(string $room, DateTimeImmutable $night): ?PriceNight
    {
        return $this->rows[$room][Days::key($night)] ?? null;
    }

    /**
     * room code => list of nights, for the relations.
     *
     * @return array<string, list<PriceNight>>
     */
    public function lists(): array
    {
        return array_map(static fn (array $nights): array => array_values($nights), $this->rows);
    }

    /**
     * room code => night of the given date, for the ladder.
     *
     * @return array<string, PriceNight>
     */
    public function night(DateTimeImmutable $night): array
    {
        $key = Days::key($night);
        $out = [];
        foreach ($this->rows as $room => $nights) {
            if (isset($nights[$key])) {
                $out[$room] = $nights[$key];
            }
        }

        return $out;
    }

    /**
     * The price read before the rule in force moved it, or null when it did not.
     */
    public function priceRead(string $room, DateTimeImmutable $night): ?float
    {
        return $this->read[$room . '|' . Days::key($night)] ?? null;
    }

    /**
     * The floor in force: the calendar's, else the date rule's, else the listing minimum.
     */
    public function floorInForce(RoomType $room, DateTimeImmutable $night): float
    {
        return LiveBounds::floor($this->row($room->code, $night), $this->inputs->override($room->code, $night), $room->base) ?? $room->min;
    }

    /**
     * The ceiling in force: the calendar's, else the date rule's, else the listing maximum.
     */
    public function ceilingInForce(RoomType $room, DateTimeImmutable $night): float
    {
        return LiveBounds::ceiling($this->row($room->code, $night), $this->inputs->override($room->code, $night), $room->base) ?? $room->max;
    }

    /**
     * Units sold and on sale the pace reads for a room night: the room's own, or the group of its
     * property for a small room (pace.ocupacao_pousada).
     *
     * @return array{int, int}
     */
    public function paceBasis(RoomType $room, DateTimeImmutable $night): array
    {
        $own = $this->row($room->code, $night);
        $sales = [];
        foreach ($this->night($night) as $code => $row) {
            $sales[$code] = [min($row->sold, $row->total), $row->total];
        }
        $group = $this->basis->groupFor($room, array_values($this->inputs->rooms), $sales);

        return $group ?? [$own?->sold ?? 0, $own?->total ?? $room->units];
    }

    private function withinRule(RoomType $room, PriceNight $night, ?\Ritmo\Domain\Model\DateOverride $rule, string $key): PriceNight
    {
        $price = RuleInForce::price($night->price, RuleInForce::floor($rule, $room->base), RuleInForce::ceiling($rule, $room->base));
        if ($price === $night->price) {
            return $night;
        }
        $this->read[$room->code . '|' . $key] = $night->price;

        return new PriceNight($night->date, $price, $night->minPrice, $night->maxPrice, $night->minStay, $night->sold, $night->total, $night->hasDateOverride, $night->userPrice, $night->uncustomizedPrice, $night->unbookable);
    }
}
