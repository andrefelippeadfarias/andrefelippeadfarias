<?php

declare(strict_types=1);

namespace Ritmo\Domain\Pricing\Ladder;

use Ritmo\Domain\Config\Field;
use Ritmo\Domain\Config\InvalidConfig;
use Ritmo\Domain\Config\ValidationError;

/**
 * The "ladder" section: the sales ladder switch and its strategies.
 *
 * Neutral default: off, no strategy. The owner's strategy lives only in the parity fixture
 * (tests/fixtures/presets/parity-ladder.json).
 */
final class LadderConfig
{
    public const ROOT = 'ladder';

    /**
     * @param list<LadderStrategy> $strategies
     */
    public function __construct(
        public readonly bool $enabled = false,
        public readonly array $strategies = [],
    ) {
    }

    /**
     * The strategies that may act: the ladder is on and the strategy is enabled.
     *
     * @return list<LadderStrategy>
     */
    public function activeStrategies(): array
    {
        return $this->enabled ? array_values(array_filter($this->strategies, static fn (LadderStrategy $s): bool => $s->enabled)) : [];
    }

    /**
     * @param array<string, mixed> $data the "ladder" section; missing keys take the neutral defaults
     *
     * @throws InvalidConfig with every problem found
     */
    public static function fromArray(array $data): self
    {
        $reader = new ConfigReader(self::fields());
        $d = $reader->open(self::ROOT, $data);
        $strategies = [];
        foreach ($reader->items($d['strategies'], 'ladder.strategies') as $i => $row) {
            $strategies[] = self::strategy($reader, $row, "ladder.strategies.$i");
        }
        $ids = array_map(static fn (LadderStrategy $s): string => $s->id, $strategies);
        if (count(array_unique($ids)) !== count($ids)) {
            $reader->add('ladder.strategies', ValidationError::UNIQUE, 'must not repeat a strategy id');
        }
        $config = new self($reader->bool($d['enabled'], 'ladder.enabled'), $strategies);
        $reader->finish('ladder');

        return $config;
    }

    /**
     * @return list<Field>
     */
    public static function fields(): array
    {
        return LadderFields::all();
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function strategy(ConfigReader $r, array $row, string $p): LadderStrategy
    {
        $from = $r->date($row['valid_from'], "$p.valid_from");
        $to = $r->date($row['valid_to'], "$p.valid_to");
        if ($from !== null && $to !== null && $to < $from) {
            $r->add("$p.valid_to", ValidationError::ORDER, 'must not be before the first night');
        }
        $rules = [];
        foreach ($r->items($row['rules'], "$p.rules") as $i => $rule) {
            $rules[] = self::rule($r, $rule, "$p.rules.$i");
        }
        self::checkDerivations($r, $rules, "$p.rules");
        $override = $row['weekend_floor_override'];

        return new LadderStrategy(
            $r->string($row['id'], "$p.id"),
            $r->string($row['label'], "$p.label"),
            $r->bool($row['enabled'], "$p.enabled"),
            (string) $r->choice($row['mode'], "$p.mode"),
            $from,
            $to,
            $r->date($row['activated_on'], "$p.activated_on"),
            $r->nullableString($row['authority_order_id'], "$p.authority_order_id"),
            $r->bool($row['lock_ceiling'], "$p.lock_ceiling"),
            new LadderSteps($r->float($row['step']['trigger_fraction'], "$p.step.trigger_fraction"), $r->float($row['step']['percent_per_unit'], "$p.step.percent_per_unit")),
            self::drop($r, $row['drop'], "$p.drop"),
            $r->bool($override['enabled'], "$p.weekend_floor_override.enabled"),
            self::ints($r, $override['weekdays'], "$p.weekend_floor_override.weekdays"),
            $rules,
            self::between($r, $row['relations']['between'], "$p.relations.between"),
            self::premium($r, $row['relations']['premium'], "$p.relations.premium"),
            self::lastMinute($r, $row['last_minute_floor'], "$p.last_minute_floor"),
            self::proportional($r, $row['proportional'], "$p.proportional"),
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function rule(ConfigReader $r, array $row, string $p): LadderRule
    {
        $opening = $row['opening'];
        $ceiling = $row['ceiling'];
        $derive = $row['derive'];
        $series = $r->nullableString($opening['series'], "$p.opening.series");
        $fromRoom = $r->nullableString($derive['from_room'], "$p.derive.from_room");
        $ceilingSeries = $r->nullableString($ceiling['series'], "$p.ceiling.series");
        $rooms = self::texts($r, $row['rooms'], "$p.rooms");
        $tags = self::texts($r, $row['room_tags'], "$p.room_tags");
        if ($rooms === [] && $tags === []) {
            $r->add("$p.rooms", ValidationError::REQUIRED, 'must list rooms or room tags');
        }
        if ($series === null && $fromRoom === null) {
            $r->add("$p.opening.series", ValidationError::REQUIRED, 'is required unless the rule derives from another room');
        }

        return new LadderRule(
            self::texts($r, $row['night_types'], "$p.night_types"),
            $rooms,
            $tags,
            $series === null ? null : new RulerPoint($series, $r->float($opening['percentile'], "$p.opening.percentile"), $r->float($opening['multiplier'], "$p.opening.multiplier")),
            $r->choice($opening['cap'], "$p.opening.cap") === 'reference_snapshot',
            $ceilingSeries === null ? null : new RulerPoint($ceilingSeries, $r->float($ceiling['percentile'], "$p.ceiling.percentile")),
            $fromRoom === null ? null : new Derivation($fromRoom, $r->float($derive['multiplier'], "$p.derive.multiplier")),
            $r->bool($row['apply_quality_floor'], "$p.apply_quality_floor"),
            $r->bool($row['apply_drop'], "$p.apply_drop"),
        );
    }

    /**
     * A derived room may not derive, directly or through others, from itself.
     *
     * @param list<LadderRule> $rules
     */
    private static function checkDerivations(ConfigReader $r, array $rules, string $path): void
    {
        $edges = [];
        foreach ($rules as $rule) {
            foreach ($rule->derive === null ? [] : $rule->rooms as $room) {
                $edges[$room][] = $rule->derive->fromRoom;
            }
        }
        foreach (array_keys($edges) as $start) {
            $seen = [];
            $queue = $edges[$start];
            while ($queue !== []) {
                $next = array_shift($queue);
                if ($next === $start) {
                    $r->add($path, ValidationError::REFERENCE, sprintf('room "%s" derives its price from itself', $start));
                    break;
                }
                if (!isset($seen[$next])) {
                    $seen[$next] = true;
                    array_push($queue, ...($edges[$next] ?? []));
                }
            }
        }
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function drop(ConfigReader $r, array $row, string $p): DropRule
    {
        return new DropRule(
            $r->bool($row['enabled'], "$p.enabled"),
            $r->int($row['window_days'], "$p.window_days"),
            $r->float($row['occupancy_below'], "$p.occupancy_below"),
            $r->float($row['percent'], "$p.percent"),
            $r->bool($row['skip_inside_window_at_activation'], "$p.skip_inside_window_at_activation"),
        );
    }

    /**
     * @return list<BetweenRule>
     */
    private static function between(ConfigReader $r, mixed $rows, string $p): array
    {
        $out = [];
        foreach ($r->items($rows, $p) as $i => $row) {
            $out[] = new BetweenRule(
                $r->string($row['room'] ?? null, "$p.$i.room"),
                $r->string($row['low'] ?? null, "$p.$i.low"),
                $r->string($row['high'] ?? null, "$p.$i.high"),
                $r->float($row['position'], "$p.$i.position"),
            );
        }

        return $out;
    }

    /**
     * @return list<PremiumRule>
     */
    private static function premium(ConfigReader $r, mixed $rows, string $p): array
    {
        $out = [];
        foreach ($r->items($rows, $p) as $i => $row) {
            $out[] = new PremiumRule(
                $r->string($row['room'] ?? null, "$p.$i.room"),
                self::texts($r, $row['over'] ?? null, "$p.$i.over"),
                $r->float($row['rate_weekend_or_holiday'], "$p.$i.rate_weekend_or_holiday"),
                $r->float($row['rate_other'], "$p.$i.rate_other"),
            );
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function lastMinute(ConfigReader $r, array $row, string $p): LastMinuteFloorRule
    {
        $rooms = [];
        foreach ($r->items($row['rooms'], "$p.rooms") as $room => $multiplier) {
            $rooms[(string) $room] = $r->float($multiplier, "$p.rooms.$room");
        }

        return new LastMinuteFloorRule(
            $r->bool($row['enabled'], "$p.enabled"),
            self::texts($r, $row['night_types'], "$p.night_types"),
            self::ints($r, $row['excluded_weekdays'], "$p.excluded_weekdays"),
            $r->bool($row['skip_holidays'], "$p.skip_holidays"),
            $r->int($row['window_days'], "$p.window_days"),
            $r->nullableString($row['series'], "$p.series"),
            $r->float($row['percentile'], "$p.percentile"),
            $rooms,
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function proportional(ConfigReader $r, array $row, string $p): ProportionalRule
    {
        $temporary = $row['temporary_floor'];

        return new ProportionalRule(
            $r->float($row['fallback_step_percent'], "$p.fallback_step_percent"),
            $r->nullableString($row['band_room'], "$p.band_room"),
            $r->nullableString($row['band_reference_room'], "$p.band_reference_room"),
            $r->float($row['band_factor'], "$p.band_factor"),
            self::texts($r, $row['band_cap_rooms'], "$p.band_cap_rooms"),
            $r->float($row['band_cap_discount'], "$p.band_cap_discount"),
            $r->nullableFloat($temporary['amount'], "$p.temporary_floor.amount"),
            $r->date($temporary['until'], "$p.temporary_floor.until"),
        );
    }

    /**
     * @return list<string>
     */
    private static function texts(ConfigReader $r, mixed $rows, string $p): array
    {
        $out = [];
        foreach ($r->items($rows, $p) as $i => $text) {
            $out[] = $r->string($text, "$p.$i");
        }

        return $out;
    }

    /**
     * @return list<int>
     */
    private static function ints(ConfigReader $r, mixed $rows, string $p): array
    {
        $out = [];
        foreach ($r->items($rows, $p) as $i => $value) {
            $out[] = $r->int($value, "$p.$i");
        }

        return $out;
    }
}
