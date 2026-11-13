<?php

declare(strict_types=1);

namespace Ritmo\Domain\Config;

use Ritmo\Domain\Alerts\AlertsConfig;
use Ritmo\Domain\Calendar\Stay\StayConfig;
use Ritmo\Domain\Calendar\Stay\StayFields;
use Ritmo\Domain\Market\Series\LadderSeriesCheck;
use Ritmo\Domain\Market\Series\MarketConfig;
use Ritmo\Domain\Market\Series\MarketFields;
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
use Ritmo\Domain\Report\Config\ReportsConfig;
use Ritmo\Domain\Report\Config\ReportsFields;
use Ritmo\Domain\Stay\Write\StayWriteConfig;
use Ritmo\Domain\Stay\Write\StayWriteFields;
use Ritmo\Domain\Support\Days;
use Ritmo\Domain\Write\ApprovalConfig;
use Ritmo\Domain\Write\Hold\WaveConfig;
use Ritmo\Domain\Write\QuotaConfig;
use Ritmo\Domain\Write\ThirdParty\ThirdPartyConfig;
use Ritmo\Domain\Write\VerifyConfig;
use Ritmo\Domain\Write\WriteConfig;

/**
 * Checks a rule set array against the schema and the rules that span more than one field.
 *
 * Validation is strict: every field must be present (use Schema::withDefaults() first when
 * importing), unknown keys are errors and types are not coerced. It returns every error found,
 * each with the dotted path of the value it is about; an empty list means the array is valid.
 */
final class RuleSetValidator
{
    /** @var list<ValidationError> */
    private array $errors = [];

    /**
     * @param array<int|string, mixed> $data
     *
     * @return list<ValidationError>
     */
    public function validate(array $data): array
    {
        $this->errors = [];
        $this->checkGroup('', '', $data);
        if ($this->errors === []) {
            $this->checkAcrossFields($data);
        }

        return $this->errors;
    }

    /**
     * Checks one top-level section alone (the same rules as validate(), without the rules that
     * span sections). A module reading its own section uses it.
     *
     * @return list<ValidationError>
     */
    public function validateSection(string $root, mixed $value): array
    {
        $this->errors = [];
        $this->check(Schema::ruleSet()->field($root), $root, $value);

        return $this->errors;
    }

    private function add(string $path, string $code, string $message): void
    {
        $this->errors[] = new ValidationError($path, $code, $message);
    }

    private function check(Field $field, string $path, mixed $value): void
    {
        if ($value === null) {
            if (!$field->nullable) {
                $this->add($path, ValidationError::REQUIRED, 'is required');
            }

            return;
        }

        if ($field->type === FieldType::Group) {
            if (is_array($value)) {
                $this->checkGroup($field->key, $path, $value);
            } else {
                $this->add($path, ValidationError::TYPE, 'must be a group of fields');
            }
        } elseif ($field->type === FieldType::Map) {
            $this->checkMap($field, $path, $value);
        } elseif ($field->type === FieldType::ListOf) {
            $this->checkList($field, $path, $value);
        } else {
            $this->checkScalar($field, $path, $value);
        }
    }

    /**
     * @param array<int|string, mixed> $value
     */
    private function checkGroup(string $key, string $path, array $value): void
    {
        $children = Schema::ruleSet()->children($key);
        foreach ($children as $name => $child) {
            $childPath = $path === '' ? (string) $name : $path . '.' . $name;
            if (!array_key_exists($name, $value)) {
                $this->add($childPath, ValidationError::REQUIRED, 'is required');
                continue;
            }
            $this->check($child, $childPath, $value[$name]);
        }
        foreach (array_keys($value) as $name) {
            if (!isset($children[$name])) {
                $this->add($path === '' ? (string) $name : $path . '.' . $name, ValidationError::UNKNOWN, 'is not a known field');
            }
        }
    }

    private function checkMap(Field $field, string $path, mixed $value): void
    {
        if (!is_array($value)) {
            $this->add($path, ValidationError::TYPE, 'must be a map');

            return;
        }
        $this->checkCount($field, $path, count($value), 'entries');
        foreach ($field->requiredKeys as $key) {
            if (!array_key_exists($key, $value)) {
                $this->add($path . '.' . $key, ValidationError::REQUIRED, 'is required');
            }
        }
        $item = Schema::ruleSet()->field($field->key . '.' . Schema::WILDCARD);
        foreach ($value as $key => $entry) {
            $entryPath = $path . '.' . $key;
            if ($field->pattern !== null && preg_match($field->pattern, (string) $key) !== 1) {
                $this->add($entryPath, ValidationError::FORMAT, 'is not a valid key');
                continue;
            }
            $this->check($item, $entryPath, $entry);
        }
    }

    private function checkList(Field $field, string $path, mixed $value): void
    {
        if (!is_array($value) || !array_is_list($value)) {
            $this->add($path, ValidationError::TYPE, 'must be a list');

            return;
        }
        $this->checkCount($field, $path, count($value), 'items');
        $item = Schema::ruleSet()->field($field->key . '.' . Schema::WILDCARD);
        foreach ($value as $index => $entry) {
            $this->check($item, $path . '.' . $index, $entry);
        }
        if ($field->unique && count($value) !== count(array_unique(array_map('json_encode', $value)))) {
            $this->add($path, ValidationError::UNIQUE, 'must not repeat items');
        }
    }

    private function checkCount(Field $field, string $path, int $count, string $noun): void
    {
        if ($field->min !== null && $count < $field->min) {
            $this->add($path, ValidationError::RANGE, sprintf('must have at least %d %s', $field->min, $noun));
        }
        if ($field->max !== null && $count > $field->max) {
            $this->add($path, ValidationError::RANGE, sprintf('must have at most %d %s', $field->max, $noun));
        }
    }

    private function checkScalar(Field $field, string $path, mixed $value): void
    {
        $problem = match ($field->type) {
            FieldType::Integer => is_int($value) ? $this->rangeProblem($field, $value) : [ValidationError::TYPE, 'must be a whole number'],
            FieldType::Number => (is_int($value) || is_float($value)) && is_finite((float) $value)
                ? $this->rangeProblem($field, $value)
                : [ValidationError::TYPE, 'must be a number'],
            FieldType::Boolean => is_bool($value) ? null : [ValidationError::TYPE, 'must be true or false'],
            FieldType::Text => is_string($value) ? $this->textProblem($field, $value) : [ValidationError::TYPE, 'must be text'],
            FieldType::Choice => in_array($value, $field->enum ?? [], true)
                ? null
                : [ValidationError::CHOICE, 'must be one of: ' . implode(', ', $field->enum ?? [])],
            FieldType::Date => is_string($value) && Days::isValid($value) ? null : [ValidationError::FORMAT, 'must be a date as YYYY-MM-DD'],
            FieldType::Variant => is_array($value) && ($value === [] || !array_is_list($value)) ? null : [ValidationError::TYPE, 'must be a group of fields'],
            default => null,
        };

        if ($problem !== null) {
            $this->add($path, $problem[0], $problem[1]);
        } elseif ($field->locked && $value !== $field->default) {
            $this->add($path, ValidationError::LOCKED, 'cannot be changed');
        }
    }

    /**
     * @return array{string, string}|null
     */
    private function rangeProblem(Field $field, int|float $value): ?array
    {
        if ($field->min !== null && ($value < $field->min || ($field->exclusiveMin && $value <= $field->min))) {
            $message = $field->exclusiveMin ? 'must be greater than %s' : 'must be at least %s';

            return [ValidationError::RANGE, sprintf($message, $field->min)];
        }
        if ($field->max !== null && $value > $field->max) {
            return [ValidationError::RANGE, sprintf('must be at most %s', $field->max)];
        }

        return null;
    }

    /**
     * @return array{string, string}|null
     */
    private function textProblem(Field $field, string $value): ?array
    {
        $length = preg_match_all('/./su', $value);
        if ($length === false) {
            return [ValidationError::FORMAT, 'must be valid UTF-8 text'];
        }
        if ($field->min !== null && ($length < $field->min || trim($value) === '')) {
            return [ValidationError::RANGE, sprintf('must have at least %d characters', $field->min)];
        }
        if ($field->max !== null && $length > $field->max) {
            return [ValidationError::RANGE, sprintf('must have at most %d characters', $field->max)];
        }
        if ($field->pattern !== null && preg_match($field->pattern, $value) !== 1) {
            return [ValidationError::FORMAT, 'has an invalid format'];
        }

        return null;
    }

    /**
     * Rules that compare fields. They run only on a structurally valid array, so every value
     * read here exists and has the right type.
     *
     * @param array<int|string, mixed> $data
     */
    private function checkAcrossFields(array $data): void
    {
        $this->checkHolidayRanges($data['calendar']['holidays']);
        $this->checkPaceThresholds($data['pace']);
        $this->checkCurves($data['pace']['curves']);
        $this->checkTargetTables($data['pace']['target_percentile']);
        $this->checkStepBands($data['pace']['max_step']);
        $this->checkRoomOverrides($data['price_factors']['room_overrides']);
        $this->checkSections($data);
    }

    /**
     * The sections of milestone M2 are read by the module that owns them, which checks what
     * spans their fields (the parameters of each relation, the references inside a ladder
     * strategy, ranges of dates...). Their errors join the others with the same paths.
     *
     * @param array<int|string, mixed> $data
     */
    private function checkSections(array $data): void
    {
        $readers = [
            'relations' => static fn (): mixed => RelationsConfig::fromArray(['relations' => $data['relations'], 'price_bands' => $data['price_bands']]),
            LadderConfig::ROOT => static fn (): mixed => LadderConfig::fromArray($data[LadderConfig::ROOT]),
            ProtectionConfig::ROOT => static fn (): mixed => ProtectionConfig::fromArray($data[ProtectionConfig::ROOT]),
            CadenceConfig::ROOT => static fn (): mixed => CadenceConfig::fromArray($data[CadenceConfig::ROOT]),
            NoEffectConfig::ROOT => static fn (): mixed => NoEffectConfig::fromArray($data[NoEffectConfig::ROOT]),
            OrdersConfig::ROOT => static fn (): mixed => OrdersConfig::fromArray($data[OrdersConfig::ROOT]),
            StayFields::ROOT => static fn (): mixed => StayConfig::fromArray($data[StayFields::ROOT]),
            PlanConfig::ROOT => static fn (): mixed => PlanConfig::fromArray($data[PlanConfig::ROOT]),
            WriteConfig::ROOT => static fn (): mixed => WriteConfig::fromArray($data[WriteConfig::ROOT]),
            QuotaConfig::ROOT => static fn (): mixed => QuotaConfig::fromArray($data[QuotaConfig::ROOT]),
            VerifyConfig::ROOT => static fn (): mixed => VerifyConfig::fromArray($data[VerifyConfig::ROOT]),
            ApprovalConfig::ROOT => static fn (): mixed => ApprovalConfig::fromArray($data[ApprovalConfig::ROOT]),
            ThirdPartyConfig::ROOT => static fn (): mixed => ThirdPartyConfig::fromArray($data[ThirdPartyConfig::ROOT]),
            WaveConfig::ROOT => static fn (): mixed => WaveConfig::fromArray($data[WaveConfig::ROOT]),
            ModesConfig::ROOT => static fn (): mixed => ModesConfig::fromArray($data[ModesConfig::ROOT]),
            ScheduleConfig::ROOT => static fn (): mixed => ScheduleConfig::fromArray($data[ScheduleConfig::ROOT]),
            LightRunConfig::ROOT => static fn (): mixed => LightRunConfig::fromArray($data[LightRunConfig::ROOT]),
            AlertsConfig::ROOT => static fn (): mixed => AlertsConfig::fromArray($data[AlertsConfig::ROOT]),
            StayWriteFields::ROOT => static fn (): mixed => StayWriteConfig::fromArray((array) ($data[StayFields::ROOT]['write'] ?? [])),
            MarketFields::ROOT => static fn (): mixed => MarketConfig::fromArray($data[MarketFields::ROOT]),
            ReportsFields::ROOT => static fn (): mixed => ReportsConfig::fromArray($data[ReportsFields::ROOT]),
        ];
        $seen = [];
        foreach ($this->errors as $error) {
            $seen[$error->path . '|' . $error->code] = true;
        }
        foreach ($readers as $root => $read) {
            try {
                $read();
            } catch (InvalidConfig $invalid) {
                foreach ($invalid->errors() as $error) {
                    $path = $error->path === $root || str_starts_with($error->path, $root . '.') || str_starts_with($error->path, 'price_bands')
                        ? $error->path
                        : $root . '.' . $error->path;
                    if (!isset($seen[$path . '|' . $error->code])) {
                        $seen[$path . '|' . $error->code] = true;
                        $this->add($path, $error->code, $error->message);
                    }
                }
            }
        }
        $this->checkLadderSeries($data, $seen);
    }

    /**
     * A sales ladder rule may only cite a series and a percentile the market builds (spec 06,
     * 1.6): otherwise it would price nothing without saying so.
     *
     * @param array<int|string, mixed> $data
     * @param array<string, true>      $seen
     */
    private function checkLadderSeries(array $data, array $seen): void
    {
        try {
            $market = MarketConfig::fromArray($data[MarketFields::ROOT]);
        } catch (InvalidConfig) {
            return; // the market section's own errors are already listed
        }
        foreach (LadderSeriesCheck::errors((array) $data[LadderConfig::ROOT], $market) as $error) {
            if (!isset($seen[$error->path . '|' . $error->code])) {
                $this->add($error->path, $error->code, $error->message);
            }
        }
    }

    /**
     * @param list<array<string, mixed>> $holidays
     */
    private function checkHolidayRanges(array $holidays): void
    {
        foreach ($holidays as $index => $holiday) {
            if ($holiday['end'] < $holiday['start']) {
                $this->add("calendar.holidays.$index.end", ValidationError::ORDER, 'must not be before the first night');
            }
        }
    }

    /**
     * @param array<string, mixed> $pace
     */
    private function checkPaceThresholds(array $pace): void
    {
        if ($pace['very_behind'] < $pace['band']) {
            $this->add('pace.very_behind', ValidationError::ORDER, 'must not be lower than the pace band');
        }
    }

    /**
     * A curve is the share sold as the night approaches: it can only stay or grow.
     *
     * @param array<string, array<int, int|float>> $curves
     */
    private function checkCurves(array $curves): void
    {
        foreach ($curves as $type => $curve) {
            $closer = null;
            foreach (array_reverse(RuleSetFields::CURVE_DAYS) as $days) {
                if ($closer !== null && $curve[$days] > $curve[$closer]) {
                    $this->add("pace.curves.$type.$days", ValidationError::ORDER, sprintf('must not be higher than the value at %d days', $closer));
                }
                $closer = $days;
            }
        }
    }

    /**
     * The better a night sells, the higher its target: a table must not go down from
     * "very behind" to "ahead, close to the night".
     *
     * @param array<string, array<string, int|float>> $tables
     */
    private function checkTargetTables(array $tables): void
    {
        foreach ($tables as $tier => $table) {
            $higher = null;
            foreach (RuleSetFields::TARGET_STATUSES as $status) {
                if ($higher !== null && $table[$status] > $table[$higher]) {
                    $this->add("pace.target_percentile.$tier.$status", ValidationError::ORDER, sprintf('must not be higher than "%s"', $higher));
                }
                $higher = $status;
            }
        }
    }

    /**
     * @param list<array{min_days: int, percent: int|float}> $bands
     */
    private function checkStepBands(array $bands): void
    {
        $starts = array_column($bands, 'min_days');
        if (!in_array(0, $starts, true)) {
            $this->add('pace.max_step', ValidationError::REQUIRED, 'needs a row starting at 0 days');
        }
        if (count($starts) !== count(array_unique($starts))) {
            $this->add('pace.max_step', ValidationError::UNIQUE, 'must not repeat a starting day');
        }
    }

    /**
     * @param list<array{room: string, price_plan: string, night_type: string, factor: int|float}> $overrides
     */
    private function checkRoomOverrides(array $overrides): void
    {
        $seen = [];
        foreach ($overrides as $index => $override) {
            $id = $override['room'] . '|' . $override['price_plan'] . '|' . $override['night_type'];
            if (isset($seen[$id])) {
                $this->add("price_factors.room_overrides.$index", ValidationError::UNIQUE, 'repeats an exception for the same room, price plan and night type');
            }
            $seen[$id] = true;
        }
    }
}
