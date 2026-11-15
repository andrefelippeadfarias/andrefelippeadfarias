<?php

declare(strict_types=1);

namespace Ritmo\Domain\Ai;

use Ritmo\Domain\Config\Field;
use Ritmo\Domain\Config\FieldType;
use Ritmo\Domain\Config\InvalidConfig;
use Ritmo\Domain\Config\RuleSet;
use Ritmo\Domain\Support\Days;
use Ritmo\Domain\Support\Num;

/**
 * Turns the structured answer of a request into a checked draft, or refuses it.
 *
 * The answer is checked again against its schema, then for meaning: rooms of the property, nights
 * from today within a year, amounts and percentages that the person wrote (never invented by the
 * model), floors of a relative change computed here from each room's base price, rule settings of
 * the catalogue only (never safety, modes, writing or quotas), in their range, applied to a copy
 * of the rules in force and validated as a whole. Texts are checked for guest data.
 */
final class DraftReader
{
    public const MAX_CHANGES = 10;
    public const MAX_QUESTIONS = 5;
    public const SUMMARY_MAX = 300;
    public const NOTE_MAX = 500;
    public const PERCENT_MIN = -50.0;
    public const PERCENT_MAX = 200.0;
    public const AMOUNT_MAX = 9999999.0;

    /** Why a draft is refused: code => English sentence (%s: the room or the setting). */
    public const PROBLEMS = [
        'no_room' => 'no room was chosen',
        'unknown_room' => '"%s" is not a room of this property',
        'amount_and_percent' => 'an order takes an amount or a percentage, not both',
        'amount_range' => 'the amount is out of range',
        'amount_not_written' => 'the amount is not one the request wrote',
        'amount_type' => 'only a minimum price or a market target order takes an amount',
        'percent_range' => 'the percentage is out of range',
        'percent_not_written' => 'the percentage is not one the request wrote',
        'percent_type' => 'a relative change becomes a minimum price order',
        'no_base' => 'room "%s" has no base price to compute from',
        'floor_without_amount' => 'a minimum price order needs an amount or a percentage',
        'percentile_range' => 'a market percentile goes from 1 to 110, on a market target order',
        'percentile_not_written' => 'the percentile is not one the request wrote',
        'dates' => 'the first and the last night are dates',
        'nights_range' => 'the nights go from today to at most a year ahead, the first before the last',
        'weekday' => 'a day of the week goes from 0 (Monday) to 6 (Sunday)',
        'no_night' => 'no night between the first and the last falls on those days of the week',
        'changes_count' => 'a rule change draft changes from 1 to 10 settings',
        'setting_denied' => '"%s" is not a setting the assistant may change',
        'setting_twice' => '"%s" is changed twice',
        'setting_missing' => '"%s" does not exist in the rules in force',
        'setting_value' => '"%s" does not take that value',
        'setting_not_written' => 'the value of "%s" is not one the request wrote',
        'no_change' => 'nothing would change: the rules already hold these values',
        'rules_invalid' => 'the rules would not be valid at "%s"',
    ];

    public function __construct(private readonly RuleCatalogue $catalogue)
    {
    }

    /**
     * @param mixed $answer the decoded JSON of the answer
     *
     * @throws AiStopped invalid_output, privacy, not_supported or draft_refused
     */
    public function read(mixed $answer, DraftContext $context): AiDraft
    {
        $shape = JsonShape::problems($answer, OutputSchemas::draft());
        if ($shape !== [] || !is_array($answer)) {
            throw new AiStopped(AiStopped::INVALID_OUTPUT, 'The assistant answered in a shape the plugin does not accept; nothing was kept.', ['problems' => array_slice($shape, 0, 5)]);
        }
        $summary = trim(mb_substr((string) $answer['summary'], 0, self::SUMMARY_MAX));
        $questions = array_slice(array_values(array_filter(array_map(static fn (string $q): string => trim(mb_substr($q, 0, self::SUMMARY_MAX)), $answer['questions']), static fn (string $q): bool => $q !== '')), 0, self::MAX_QUESTIONS);
        PrivacyGuard::assertSendable($summary, ...$questions);

        if ($answer['kind'] === 'not_supported') {
            throw new AiStopped(AiStopped::NOT_SUPPORTED, 'The assistant cannot turn this request into a draft.', ['summary' => $summary, 'questions' => $questions]);
        }
        if ($answer['kind'] === AiDraft::ORDER) {
            return new AiDraft(AiDraft::ORDER, $summary, $this->order($answer['order'], $context), [], $questions);
        }

        return new AiDraft(AiDraft::RULE_CHANGE, $summary, null, $this->ruleChanges($answer['rule_changes'], $context), $questions);
    }

    /**
     * @param array<string, mixed> $order
     *
     * @throws AiStopped
     */
    private function order(array $order, DraftContext $context): OrderDraft
    {
        $problems = [];
        $type = (string) $order['type'];
        $codes = array_keys($context->roomBases);
        $rooms = array_values(array_unique(array_map('strval', $order['rooms'])));
        if (in_array('*', $rooms, true)) {
            $rooms = ['*'];
        } elseif ($rooms === []) {
            $problems[] = ['no_room', ''];
        }
        foreach ($rooms as $room) {
            if ($room !== '*' && !in_array($room, $codes, true)) {
                $problems[] = ['unknown_room', $room];
            }
        }
        $covered = $rooms === ['*'] ? $codes : $rooms;

        [$from, $to, $nights] = $this->nights($order, $context, $problems);

        $amount = (float) $order['floor_amount'];
        $percent = (float) $order['change_percent'];
        $floors = [];
        $derivation = [];
        if ($amount !== 0.0 && $percent !== 0.0) {
            $problems[] = ['amount_and_percent', ''];
        } elseif ($amount !== 0.0) {
            if ($amount < 0 || $amount > self::AMOUNT_MAX) {
                $problems[] = ['amount_range', ''];
            } elseif (!NumberCheck::mentions($context->request, $amount)) {
                $problems[] = ['amount_not_written', ''];
            } elseif (!in_array($type, ['floor', 'target'], true)) {
                $problems[] = ['amount_type', ''];
            } else {
                foreach ($covered as $room) {
                    $floors[$room] = self::money($amount);
                }
            }
        } elseif ($percent !== 0.0) {
            if ($percent < self::PERCENT_MIN || $percent > self::PERCENT_MAX) {
                $problems[] = ['percent_range', ''];
            } elseif (!NumberCheck::mentions($context->request, abs($percent))) {
                $problems[] = ['percent_not_written', ''];
            } elseif ($type !== 'floor') {
                $problems[] = ['percent_type', ''];
            } else {
                foreach ($covered as $room) {
                    $base = (float) ($context->roomBases[$room] ?? 0.0);
                    if ($base <= 0) {
                        $problems[] = ['no_base', $room];
                        continue;
                    }
                    $floor = Num::roundHalfEven($base * (100 + $percent) / 100, 2);
                    $floors[$room] = self::money($floor);
                    $derivation[] = ['room' => $room, 'base' => $base, 'percent' => $percent, 'floor' => $floor];
                }
            }
        } elseif ($type === 'floor') {
            $problems[] = ['floor_without_amount', ''];
        }

        $percentile = (int) $order['percentile'];
        if ($percentile !== 0) {
            if ($type !== 'target' || $percentile < 1 || $percentile > 110) {
                $problems[] = ['percentile_range', ''];
            } elseif (!NumberCheck::mentions($context->request, (float) $percentile)) {
                $problems[] = ['percentile_not_written', ''];
            }
        }
        $note = trim(mb_substr((string) $order['note'], 0, self::NOTE_MAX));
        PrivacyGuard::assertSendable($note);

        if ($problems !== []) {
            throw self::refused($problems);
        }

        return new OrderDraft($type, $rooms, $from, $to, $nights, $floors, $percentile === 0 ? null : $percentile, $note, $derivation);
    }

    /**
     * @param array<string, mixed>              $order
     * @param list<array{0: string, 1: string}> $problems
     *
     * @return array{0: string, 1: string, 2: list<string>}
     */
    private function nights(array $order, DraftContext $context, array &$problems): array
    {
        $from = (string) $order['from'];
        $to = (string) $order['to'];
        if (!Days::isValid($from) || !Days::isValid($to)) {
            $problems[] = ['dates', ''];

            return ['', '', []];
        }
        $first = Days::date($from);
        $last = Days::date($to);
        if (Days::diff($context->today, $first) < 0 || Days::diff($last, $context->lastNight()) < 0 || Days::diff($first, $last) < 0) {
            $problems[] = ['nights_range', ''];

            return [$from, $to, []];
        }
        $weekdays = array_values(array_unique(array_map('intval', $order['weekdays'])));
        if (array_filter($weekdays, static fn (int $d): bool => $d < 0 || $d > 6) !== []) {
            $problems[] = ['weekday', ''];

            return [$from, $to, []];
        }
        if ($weekdays === [] || count($weekdays) === 7) {
            return [$from, $to, []];
        }
        $nights = [];
        for ($night = $first; Days::diff($night, $last) >= 0; $night = Days::add($night, 1)) {
            if (in_array(Days::weekday($night), $weekdays, true)) {
                $nights[] = Days::key($night);
            }
        }
        if ($nights === []) {
            $problems[] = ['no_night', ''];
        }

        return [$nights[0] ?? $from, $nights === [] ? $to : $nights[count($nights) - 1], $nights];
    }

    /**
     * @param list<array<string, mixed>> $changes
     *
     * @return list<RuleChange>
     *
     * @throws AiStopped
     */
    private function ruleChanges(array $changes, DraftContext $context): array
    {
        if ($changes === [] || count($changes) > self::MAX_CHANGES) {
            throw self::refused([['changes_count', '']]);
        }
        $problems = [];
        $rules = $context->rules;
        $out = [];
        $seen = [];
        foreach ($changes as $change) {
            $path = (string) $change['key'];
            $field = $this->catalogue->fieldFor($path);
            if ($field === null) {
                $problems[] = ['setting_denied', $path];
                continue;
            }
            if (isset($seen[$path])) {
                $problems[] = ['setting_twice', $path];
                continue;
            }
            $seen[$path] = true;
            if (!self::exists($rules, $path)) {
                $problems[] = ['setting_missing', $path];
                continue;
            }
            $value = self::typed($field, trim((string) $change['value']));
            if ($value === null) {
                $problems[] = ['setting_value', $path];
                continue;
            }
            if ((is_int($value) || is_float($value)) && !self::requested($field, (float) $value, $context->request)) {
                $problems[] = ['setting_not_written', $path];
                continue;
            }
            $before = self::at($rules, $path);
            if ($before === $value || ((is_int($before) || is_float($before)) && (is_int($value) || is_float($value)) && (float) $before === (float) $value)) {
                continue;
            }
            $rules = self::put($rules, explode('.', $path), $value);
            $out[] = new RuleChange($path, $field->key, $field->label, $before, $value);
        }
        if ($problems === [] && $out === []) {
            $problems[] = ['no_change', ''];
        }
        if ($problems === []) {
            try {
                RuleSet::fromArray($rules);
            } catch (InvalidConfig $e) {
                foreach (array_slice($e->errors(), 0, 5) as $error) {
                    $problems[] = ['rules_invalid', $error->path];
                }
            }
        }
        if ($problems !== []) {
            throw self::refused($problems);
        }

        return $out;
    }

    private static function typed(Field $field, string $text): int|float|bool|string|null
    {
        $value = match ($field->type) {
            FieldType::Integer => preg_match('/^-?\d{1,9}$/', $text) === 1 ? (int) $text : null,
            FieldType::Number => preg_match('/^-?\d{1,9}([.,]\d{1,6})?$/', $text) === 1 ? (float) str_replace(',', '.', $text) : null,
            FieldType::Boolean => match (strtolower($text)) {
                'yes', 'true', 'on' => true,
                'no', 'false', 'off' => false,
                default => null,
            },
            FieldType::Choice => self::choice($field, $text),
            default => null,
        };
        if (is_int($value) || is_float($value)) {
            $tooLow = $field->min !== null && ($field->exclusiveMin ? $value <= $field->min : $value < $field->min);
            if ($tooLow || ($field->max !== null && $value > $field->max)) {
                return null;
            }
        }

        return $value;
    }

    private static function choice(Field $field, string $text): int|float|string|null
    {
        foreach ($field->enum ?? [] as $option) {
            if ((is_string($option) || is_int($option) || is_float($option)) && (string) $option === $text) {
                return $option;
            }
        }

        return null;
    }

    /**
     * A numeric value must be one the person wrote: as written, or as a percentage of a share.
     */
    private static function requested(Field $field, float $value, string $request): bool
    {
        if (NumberCheck::mentions($request, abs($value))) {
            return true;
        }

        return in_array($field->unit, [Field::UNIT_RATIO, Field::UNIT_PERCENT], true) && NumberCheck::mentions($request, Num::roundHalfEven(abs($value) * 100, 6));
    }

    /**
     * @param array<int|string, mixed> $data
     */
    private static function exists(array $data, string $path): bool
    {
        foreach (explode('.', $path) as $segment) {
            $key = ctype_digit($segment) ? (int) $segment : $segment;
            if (!is_array($data) || !array_key_exists($key, $data)) {
                return false;
            }
            $data = $data[$key];
        }

        return true;
    }

    /**
     * @param array<int|string, mixed> $data
     */
    private static function at(array $data, string $path): mixed
    {
        foreach (explode('.', $path) as $segment) {
            $data = is_array($data) ? ($data[ctype_digit($segment) ? (int) $segment : $segment] ?? null) : null;
        }

        return $data;
    }

    /**
     * @param array<int|string, mixed> $data
     * @param list<string>             $segments
     *
     * @return array<int|string, mixed>
     */
    private static function put(array $data, array $segments, mixed $value): array
    {
        $segment = array_shift($segments);
        $key = ctype_digit((string) $segment) ? (int) $segment : (string) $segment;
        $data[$key] = $segments === [] ? $value : self::put(is_array($data[$key] ?? null) ? $data[$key] : [], $segments, $value);

        return $data;
    }

    private static function money(float $amount): string
    {
        return sprintf('%.2f', Num::roundHalfEven($amount, 2));
    }

    /**
     * @param list<array{0: string, 1: string}> $problems code and subject (a room or a setting path)
     */
    private static function refused(array $problems): AiStopped
    {
        $problems = array_slice($problems, 0, 5);
        $codes = [];
        $sentences = [];
        foreach ($problems as [$code, $subject]) {
            $subject = mb_substr((string) preg_replace('/[^A-Za-z0-9_.*-]/', '', $subject), 0, 80);
            $codes[] = $subject === '' ? $code : $code . ':' . $subject;
            $sentences[] = sprintf(self::PROBLEMS[$code], $subject);
        }

        return new AiStopped(AiStopped::DRAFT_REFUSED, 'The plugin did not accept the assistant\'s draft: ' . implode('; ', $sentences) . '.', ['problems' => $codes]);
    }
}
