<?php

declare(strict_types=1);

namespace Ritmo\Domain\Config;

use DateTimeInterface;
use OutOfBoundsException;
use Ritmo\Domain\Model\NightType;
use Ritmo\Domain\Model\PaceStatus;
use Ritmo\Domain\Model\PricePlan;
use Ritmo\Domain\Model\Property;
use Ritmo\Domain\Support\DateRange;
use Ritmo\Domain\Support\Days;

/**
 * A validated, immutable set of rules with typed access.
 *
 * It can only be built from an array that passes RuleSetValidator, so the engine never has to
 * check a rule again. The array is kept in canonical form (schema order, numbers as floats,
 * map keys sorted), which makes {@see hash()} a stable identity for a version of the rules.
 */
final class RuleSet
{
    /** @var list<Holiday>|null built on first use */
    private ?array $holidays = null;

    /**
     * @param array<string, mixed> $data canonical, validated
     */
    private function __construct(private readonly array $data)
    {
    }

    /**
     * Builds a rule set from a complete array (every field present).
     *
     * @param array<int|string, mixed> $data
     *
     * @throws InvalidConfig with every validation error found
     */
    public static function fromArray(array $data): self
    {
        $errors = (new RuleSetValidator())->validate($data);
        if ($errors !== []) {
            throw new InvalidConfig('rule set', $errors);
        }

        return new self(self::canonicalGroup(Schema::ruleSet(), '', $data));
    }

    /**
     * The neutral rule set made of the schema defaults.
     */
    public static function defaults(): self
    {
        return self::fromArray(Schema::ruleSet()->defaults());
    }

    /**
     * The rules as a plain array in canonical form, ready to store or export.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->data;
    }

    /**
     * SHA-256 of the canonical rules: equal rule sets have equal hashes.
     */
    public function hash(): string
    {
        $flags = JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR;

        return hash('sha256', json_encode($this->data, $flags));
    }

    /**
     * The value at a dotted path ("pace.band", "calendar.holidays.0.name").
     *
     * @throws OutOfBoundsException when the path does not exist
     */
    public function get(string $path): mixed
    {
        $value = $this->data;
        foreach (explode('.', $path) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                throw new OutOfBoundsException(sprintf('No rule at "%s".', $path));
            }
            $value = $value[$segment];
        }

        return $value;
    }

    /**
     * Occupancy goals of the rule set: window in days => percent sold, shortest window first.
     *
     * @return array<int, float>
     */
    public function occupancyGoals(): array
    {
        $goals = $this->data['occupancy']['goals'];
        ksort($goals);

        return $goals;
    }

    /**
     * The goals that apply to a property: its own when it has any, otherwise the rule set's.
     *
     * @return array<int, float>
     */
    public function occupancyGoalsFor(Property $property): array
    {
        if ($property->occupancyGoals === []) {
            return $this->occupancyGoals();
        }
        $goals = array_map('floatval', $property->occupancyGoals);
        ksort($goals);

        return $goals;
    }

    /**
     * Days of the week priced as weekend nights (Monday = 0 ... Sunday = 6).
     *
     * @return list<int>
     */
    public function weekendDays(): array
    {
        return $this->data['calendar']['weekend_days'];
    }

    /**
     * @return list<Holiday> in configured order
     */
    public function holidays(): array
    {
        return $this->holidays ??= array_map(
            static fn (array $row): Holiday => new Holiday(
                $row['name'],
                DateRange::fromStrings($row['start'], $row['end']),
                NightType::from($row['night_type']),
                $row['min_stay'],
                $row['floor'],
            ),
            $this->data['calendar']['holidays']
        );
    }

    /**
     * The first configured period that covers the night, or null.
     */
    public function holidayOn(DateTimeInterface $night): ?Holiday
    {
        foreach ($this->holidays() as $holiday) {
            if ($holiday->covers($night)) {
                return $holiday;
            }
        }

        return null;
    }

    /**
     * The night type of a date: the type of the period that covers it, otherwise weekend or
     * weekday by the configured weekend days.
     */
    public function nightType(DateTimeInterface $night): NightType
    {
        $holiday = $this->holidayOn($night);
        if ($holiday !== null) {
            return $holiday->nightType;
        }

        return in_array(Days::weekday($night), $this->weekendDays(), true) ? NightType::Weekend : NightType::Weekday;
    }

    /**
     * The expected sales curve of a night type: days before the night => percent sold, from the
     * farthest point to the day itself.
     *
     * @return array<int, float>
     */
    public function paceCurve(NightType $type): array
    {
        $curve = $this->data['pace']['curves'][$type->value];
        krsort($curve);

        return $curve;
    }

    public function paceBand(): float
    {
        return $this->data['pace']['band'];
    }

    public function paceVeryBehind(): float
    {
        return $this->data['pace']['very_behind'];
    }

    /**
     * With nothing sold at this many days before the night or fewer, the night is very behind.
     */
    public function zeroSoldDays(NightType $type): int
    {
        return $this->data['pace']['zero_sold_days'][$type->value];
    }

    /**
     * Room types with this many units or fewer are placed on the curve by the occupancy of
     * their group instead of their own. Zero: no room is.
     */
    public function groupPaceMaxUnits(): int
    {
        return $this->data['pace']['group_pace']['max_units'];
    }

    /**
     * The group of the rule above: the room types of the same property with every one of these
     * tags (every room type when empty).
     *
     * @return list<string>
     */
    public function groupPaceRoomTags(): array
    {
        return $this->data['pace']['group_pace']['room_tags'];
    }

    /**
     * The target-percentile table of a tier; an unknown tier uses the "standard" table.
     *
     * @return array{ahead_near: float, ahead: float, on_pace: float, behind: float, very_behind: float}
     */
    public function targetPercentiles(string $tier): array
    {
        $tables = $this->data['pace']['target_percentile'];

        return $tables[$tier] ?? $tables[RuleSetFields::DEFAULT_TIER];
    }

    /**
     * The market percentile a night should aim at, by tier, pace status and days left. A night
     * that is ahead within aheadNearDays() uses the "ahead, close to the night" target.
     */
    public function targetPercentile(string $tier, PaceStatus $status, int $daysOut): float
    {
        $table = $this->targetPercentiles($tier);
        if ($status === PaceStatus::Ahead && $daysOut <= $this->aheadNearDays()) {
            return $table['ahead_near'];
        }

        return $table[$status->value];
    }

    public function aheadNearDays(): int
    {
        return $this->data['pace']['ahead_near_days'];
    }

    /**
     * Share of the distance to the target covered by one step ("k").
     */
    public function stepStrength(): float
    {
        return $this->data['pace']['k'];
    }

    /**
     * The largest step, as a percentage, at a number of days before the night: the band with the
     * highest starting day not above $daysOut (the band starting at 0 for a night already past).
     */
    public function maxStepPercent(int $daysOut): float
    {
        $best = null;
        foreach ($this->data['pace']['max_step'] as $band) {
            if ($band['min_days'] <= max(0, $daysOut) && ($best === null || $band['min_days'] > $best['min_days'])) {
                $best = $band;
            }
        }

        return $best['percent'];
    }

    /**
     * A market anchor built from fewer competitors than this is weak.
     */
    public function minSampleSize(): int
    {
        return $this->data['pace']['min_n'];
    }

    /**
     * A market anchor older than this many days is weak.
     */
    public function maxSampleAgeDays(): int
    {
        return $this->data['pace']['max_age_days'];
    }

    /**
     * Relative change below which no step is produced.
     */
    public function tolerance(): float
    {
        return $this->data['pace']['tolerance'];
    }

    /**
     * Market percentile used as the floor of a sold-out night.
     */
    public function protectionPercentile(): float
    {
        return $this->data['pace']['protection_percentile'];
    }

    /**
     * Fraction of a room's reference minimum that no price may go below.
     */
    public function referenceMinFraction(): float
    {
        return $this->data['floors']['fraction_of_reference_min'];
    }

    /**
     * @return list<FloorRule> in configured order
     */
    public function ownerFloors(): array
    {
        return array_map(
            static fn (array $row): FloorRule => new FloorRule(
                $row['amount'],
                PricePlan::from($row['price_plan']),
                [NightType::from($row['night_type'])],
                $row['room_tags'],
            ),
            $this->data['floors']['owner']
        );
    }

    public function qualityFloor(): FloorRule
    {
        $row = $this->data['floors']['quality_floor'];

        return new FloorRule(
            $row['amount'],
            PricePlan::from($row['price_plan']),
            array_map(static fn (string $type): NightType => NightType::from($type), $row['night_types']),
            $row['room_tags'],
        );
    }

    /**
     * The general floor of holiday nights. Holiday::$floor, when set, is in the same price plan.
     */
    public function holidayFloor(): FloorRule
    {
        $row = $this->data['floors']['holiday_floor'];

        return new FloorRule($row['amount'], PricePlan::from($row['price_plan']), [NightType::Holiday]);
    }

    /**
     * The measured factor of a price plan: plan price = sent price x factor.
     *
     * The sent plan is 1 by definition. Otherwise a room exception wins, then the table of the
     * property, then the "*" table.
     */
    public function priceFactor(string $propertyId, PricePlan $plan, NightType $type, ?string $roomCode = null): float
    {
        if ($plan === PricePlan::Sent) {
            return 1.0;
        }

        $factors = $this->data['price_factors'];
        foreach ($factors['room_overrides'] as $override) {
            if ($override['room'] === $roomCode && $override['price_plan'] === $plan->value && $override['night_type'] === $type->value) {
                return $override['factor'];
            }
        }
        $table = $factors['properties'][$propertyId] ?? $factors['properties'][RuleSetFields::ANY_PROPERTY];

        return $table[$plan->value][$type->value];
    }

    /**
     * Largest fraction a listing's base, minimum or maximum may move within one week.
     */
    public function weeklyListingChangeMax(): float
    {
        return $this->data['safety']['weekly_listing_change_max'];
    }

    /**
     * Always true: the price of tonight is never increased.
     */
    public function neverRaiseToday(): bool
    {
        return $this->data['safety']['never_raise_today'];
    }

    /**
     * @param array<int|string, mixed> $value
     *
     * @return array<int|string, mixed>
     */
    private static function canonicalGroup(Schema $schema, string $key, array $value): array
    {
        $canonical = [];
        foreach ($schema->children($key) as $name => $child) {
            $canonical[$name] = self::canonical($schema, $child, $value[$name]);
        }

        return $canonical;
    }

    private static function canonical(Schema $schema, Field $field, mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }
        if ($field->type === FieldType::Group) {
            return self::canonicalGroup($schema, $field->key, $value);
        }
        if ($field->type === FieldType::Number) {
            return (float) $value;
        }
        if ($field->type !== FieldType::Map && $field->type !== FieldType::ListOf) {
            return $value;
        }

        $item = $schema->field($field->key . '.' . Schema::WILDCARD);
        if ($field->type === FieldType::Map) {
            ksort($value, SORT_STRING);
        }

        return array_map(static fn (mixed $entry): mixed => self::canonical($schema, $item, $entry), $value);
    }
}
