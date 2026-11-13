<?php

declare(strict_types=1);

namespace Ritmo\Application\Grid;

use DateTimeImmutable;
use Ritmo\Domain\Model\RoomType;
use Ritmo\Domain\Pricing\RuleCode;
use Ritmo\Domain\Support\Days;
use Ritmo\Domain\Support\Num;

/**
 * The plan items of one run (rows of plan_items: one per room night and field) folded into one
 * PlannedNight per room night, with the rule trace and the price the run read.
 */
final class PlanIndex
{
    /** @var array<string, PlannedNight> */
    private array $nights = [];

    /** @var array<string, list<array{code: string, params: array<string, mixed>}>> */
    private array $traces = [];

    /** @var array<string, float> */
    private array $prices = [];

    /**
     * @param iterable<array{room_code: string, night: DateTimeImmutable, field: string, current: float|null, proposed: float|null, reason: string, details: array<string, mixed>, status: string}> $items
     */
    public function __construct(iterable $items, public readonly ?int $runId = null)
    {
        $grouped = [];
        foreach ($items as $item) {
            $grouped[self::key($item['room_code'], Days::key($item['night']))][] = $item;
        }
        foreach ($grouped as $key => $rows) {
            $this->nights[$key] = self::fold($rows);
            $details = $rows[0]['details'];
            $this->traces[$key] = is_array($details['rule_trace'] ?? null) ? array_values($details['rule_trace']) : [];
            if (is_numeric($details['current_price'] ?? null)) {
                $this->prices[$key] = (float) $details['current_price'];
            }
        }
    }

    /**
     * The same plan with the listing's minimum and maximum as the amounts in force of nights that
     * had no date rule (PlannedNight::withListing).
     *
     * @param array<string, RoomType> $rooms code => room type
     */
    public function withListing(array $rooms): self
    {
        $copy = clone $this;
        foreach ($copy->nights as $key => $night) {
            $room = $rooms[explode('|', $key, 2)[0]] ?? null;
            if ($room !== null) {
                $copy->nights[$key] = $night->withListing($room->min, $room->max);
            }
        }

        return $copy;
    }

    public static function empty(): self
    {
        return new self([]);
    }

    public function night(string $room, string $night): PlannedNight
    {
        return $this->nights[self::key($room, $night)] ?? PlannedNight::none();
    }

    /**
     * @return list<array{code: string, params: array<string, mixed>}>
     */
    public function trace(string $room, string $night): array
    {
        return $this->traces[self::key($room, $night)] ?? [];
    }

    public function price(string $room, string $night): ?float
    {
        return $this->prices[self::key($room, $night)] ?? null;
    }

    /**
     * @return array<string, PlannedNight> "room|YYYY-MM-DD" => night
     */
    public function all(): array
    {
        return $this->nights;
    }

    public function isEmpty(): bool
    {
        return $this->nights === [];
    }

    public static function key(string $room, string $night): string
    {
        return $room . '|' . $night;
    }

    /**
     * @param non-empty-list<array{field: string, current: float|null, proposed: float|null, reason: string, details: array<string, mixed>, status: string}> $rows
     */
    private static function fold(array $rows): PlannedNight
    {
        $byField = [];
        foreach ($rows as $row) {
            $byField[$row['field']] = $row;
        }
        $details = $rows[0]['details'];
        $statuses = array_column($rows, 'status');
        $status = in_array(PlannedNight::REJECTED, $statuses, true) ? PlannedNight::REJECTED
            : (in_array(PlannedNight::WOULD_WRITE, $statuses, true) ? PlannedNight::WOULD_WRITE : PlannedNight::NO_CHANGE);
        $codes = array_column(is_array($details['rule_trace'] ?? null) ? $details['rule_trace'] : [], 'code');

        return new PlannedNight(
            $status,
            $rows[0]['reason'],
            is_string($details['pace_status'] ?? null) ? $details['pace_status'] : null,
            $byField['floor']['current'] ?? null,
            $byField['floor']['proposed'] ?? null,
            self::int($details['min_percent'] ?? null),
            $byField['ceiling']['current'] ?? null,
            $byField['ceiling']['proposed'] ?? null,
            self::int($details['max_percent'] ?? null),
            self::int($byField['min_stay']['current'] ?? null),
            self::int($byField['min_stay']['proposed'] ?? null),
            array_values(array_map('strval', array_filter((array) ($details['issues'] ?? []), 'is_scalar'))),
            array_values(array_map('strval', array_filter((array) ($details['relation_codes'] ?? []), 'is_scalar'))),
            is_string($details['order_id'] ?? null) ? $details['order_id'] : null,
            in_array(RuleCode::HELD_AT_FLOOR, $codes, true),
        );
    }

    private static function int(mixed $value): ?int
    {
        return is_numeric($value) ? Num::roundInt((float) $value) : null;
    }
}
