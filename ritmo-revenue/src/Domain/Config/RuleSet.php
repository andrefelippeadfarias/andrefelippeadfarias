<?php

declare(strict_types=1);

namespace Ritmo\Domain\Config;

use DateTimeInterface;
use OutOfBoundsException;
use Ritmo\Domain\Alerts\AlertsConfig;
use Ritmo\Domain\Calendar\Stay\StayConfig;
use Ritmo\Domain\Calendar\Stay\StayFields;
use Ritmo\Domain\Model\NightType;
use Ritmo\Domain\Model\PaceStatus;
use Ritmo\Domain\Model\PricePlan;
use Ritmo\Domain\Model\Property;
use Ritmo\Domain\Operations\LightRunConfig;
use Ritmo\Domain\Operations\ModesConfig;
use Ritmo\Domain\Operations\ScheduleConfig;
use Ritmo\Domain\Orders\OrdersConfig;
use Ritmo\Domain\Plan\PlanConfig;
use Ritmo\Domain\Pricing\Cadence\CadenceConfig;
use Ritmo\Domain\Pricing\Cadence\NoEffectConfig;
use Ritmo\Domain\Pricing\Ladder\LadderConfig;
use Ritmo\Domain\Pricing\Protection\ProtectionConfig;
use Ritmo\Domain\Relations\Config\RelationsConfig;
use Ritmo\Domain\Stay\Write\StayWriteConfig;
use Ritmo\Domain\Stay\Write\StayWriteFields;
use Ritmo\Domain\Support\DateRange;
use Ritmo\Domain\Support\Days;
use Ritmo\Domain\Write\ApprovalConfig;
use Ritmo\Domain\Write\Hold\WaveConfig;
use Ritmo\Domain\Write\QuotaConfig;
use Ritmo\Domain\Write\ThirdParty\ThirdPartyConfig;
use Ritmo\Domain\Write\VerifyConfig;
use Ritmo\Domain\Write\WriteConfig;

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

    /** @var array<string, object> the M2 sections, read by their modules on first use */
    private array $sections = [];

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
     * Builds a rule set stored by an earlier version of the plugin: every setting it does not have
     * yet (a section or a field added later) takes its neutral default, and what it has is kept
     * and validated as usual. Lists are never merged with the defaults.
     *
     * @param array<int|string, mixed> $stored
     *
     * @throws InvalidConfig when what was stored does not pass validation
     */
    public static function fromStored(array $stored): self
    {
        return self::fromArray(self::completed(Schema::ruleSet()->defaults(), $stored));
    }

    /**
     * @param array<int|string, mixed> $defaults
     * @param array<int|string, mixed> $stored
     *
     * @return array<int|string, mixed>
     */
    private static function completed(array $defaults, array $stored): array
    {
        if (array_is_list($defaults) || array_is_list($stored) && $stored !== []) {
            return $stored;
        }
        foreach ($defaults as $key => $default) {
            if (!array_key_exists($key, $stored)) {
                $stored[$key] = $default;
            } elseif (is_array($default) && is_array($stored[$key]) && $default !== [] && !array_is_list($default)) {
                $stored[$key] = self::completed($default, $stored[$key]);
            }
        }

        return $stored;
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
     * The top percentile of the sales ramp, or null when the ramp is off.
     */
    public function salesRampTop(): ?float
    {
        return $this->data['pace']['sales_ramp']['enabled'] ? $this->data['pace']['sales_ramp']['top_percentile'] : null;
    }

    /**
     * Relations between room types and price bands (sections "relations" and "price_bands").
     */
    public function relations(): RelationsConfig
    {
        return $this->section('relations', fn (): RelationsConfig => RelationsConfig::fromArray(['relations' => $this->data['relations'], 'price_bands' => $this->data['price_bands']]));
    }

    public function ladder(): LadderConfig
    {
        return $this->section(LadderConfig::ROOT, fn (): LadderConfig => LadderConfig::fromArray($this->data[LadderConfig::ROOT]));
    }

    /**
     * Protection of sold-out nights (section "soldout").
     */
    public function soldOut(): ProtectionConfig
    {
        return $this->section(ProtectionConfig::ROOT, fn (): ProtectionConfig => ProtectionConfig::fromArray($this->data[ProtectionConfig::ROOT]));
    }

    public function cadence(): CadenceConfig
    {
        return $this->section(CadenceConfig::ROOT, fn (): CadenceConfig => CadenceConfig::fromArray($this->data[CadenceConfig::ROOT]));
    }

    public function noEffect(): NoEffectConfig
    {
        return $this->section(NoEffectConfig::ROOT, fn (): NoEffectConfig => NoEffectConfig::fromArray($this->data[NoEffectConfig::ROOT]));
    }

    public function orders(): OrdersConfig
    {
        return $this->section(OrdersConfig::ROOT, fn (): OrdersConfig => OrdersConfig::fromArray($this->data[OrdersConfig::ROOT]));
    }

    public function stay(): StayConfig
    {
        return $this->section(StayFields::ROOT, fn (): StayConfig => StayConfig::fromArray($this->data[StayFields::ROOT]));
    }

    /**
     * Stay and arrival/departure writes (section "stay", sub-section "write").
     */
    public function stayWrite(): StayWriteConfig
    {
        return $this->section(StayWriteFields::ROOT, fn (): StayWriteConfig => StayWriteConfig::fromArray((array) ($this->data[StayFields::ROOT]['write'] ?? [])));
    }

    public function plan(): PlanConfig
    {
        return $this->section(PlanConfig::ROOT, fn (): PlanConfig => PlanConfig::fromArray($this->data[PlanConfig::ROOT]));
    }

    /**
     * The write path: freshness of reads and validation, token lifetime, call sizes (section "write").
     */
    public function write(): WriteConfig
    {
        return $this->section(WriteConfig::ROOT, fn (): WriteConfig => WriteConfig::fromArray($this->data[WriteConfig::ROOT]));
    }

    /**
     * The recalculation quota of the pricing system (section "quota").
     */
    public function quota(): QuotaConfig
    {
        return $this->section(QuotaConfig::ROOT, fn (): QuotaConfig => QuotaConfig::fromArray($this->data[QuotaConfig::ROOT]));
    }

    /**
     * Confirmation of what was written and of its arrival at the channels (section "verify").
     */
    public function verify(): VerifyConfig
    {
        return $this->section(VerifyConfig::ROOT, fn (): VerifyConfig => VerifyConfig::fromArray($this->data[VerifyConfig::ROOT]));
    }

    /**
     * How long a plan may wait for a person (section "approval").
     */
    public function approval(): ApprovalConfig
    {
        return $this->section(ApprovalConfig::ROOT, fn (): ApprovalConfig => ApprovalConfig::fromArray($this->data[ApprovalConfig::ROOT]));
    }

    /**
     * Nights someone else changed: how long they stay frozen (section "third_party").
     */
    public function thirdParty(): ThirdPartyConfig
    {
        return $this->section(ThirdPartyConfig::ROOT, fn (): ThirdPartyConfig => ThirdPartyConfig::fromArray($this->data[ThirdPartyConfig::ROOT]));
    }

    /**
     * The cancellation wave that holds price cuts (section "waves").
     */
    public function waves(): WaveConfig
    {
        return $this->section(WaveConfig::ROOT, fn (): WaveConfig => WaveConfig::fromArray($this->data[WaveConfig::ROOT]));
    }

    /**
     * When a property may leave the shadow mode (section "modes").
     */
    public function modes(): ModesConfig
    {
        return $this->section(ModesConfig::ROOT, fn (): ModesConfig => ModesConfig::fromArray($this->data[ModesConfig::ROOT]));
    }

    /**
     * When the runs of each property start (section "schedule").
     */
    public function schedule(): ScheduleConfig
    {
        return $this->section(ScheduleConfig::ROOT, fn (): ScheduleConfig => ScheduleConfig::fromArray($this->data[ScheduleConfig::ROOT]));
    }

    /**
     * What a light run reads and decides (section "light_run").
     */
    public function lightRun(): LightRunConfig
    {
        return $this->section(LightRunConfig::ROOT, fn (): LightRunConfig => LightRunConfig::fromArray($this->data[LightRunConfig::ROOT]));
    }

    /**
     * The security, below-expected and inventory alerts (section "alerts").
     */
    public function alerts(): AlertsConfig
    {
        return $this->section(AlertsConfig::ROOT, fn (): AlertsConfig => AlertsConfig::fromArray($this->data[AlertsConfig::ROOT]));
    }

    /**
     * @template T of object
     *
     * @param callable(): T $read
     *
     * @return T
     */
    private function section(string $name, callable $read): object
    {
        return $this->sections[$name] ??= $read();
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
        if ($field->type === FieldType::Variant) {
            return self::sortedKeys($value);
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

    /**
     * A variant group in canonical form: the keys of every group inside it sorted, lists kept in
     * their order (its owner reads the values).
     */
    private static function sortedKeys(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (!array_is_list($value)) {
            ksort($value, SORT_STRING);
        }

        return array_map(static fn (mixed $entry): mixed => self::sortedKeys($entry), $value);
    }
}
