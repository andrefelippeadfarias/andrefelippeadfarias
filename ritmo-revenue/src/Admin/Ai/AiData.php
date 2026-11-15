<?php

declare(strict_types=1);

namespace Ritmo\Admin\Ai;

use Ritmo\Admin\Components\DomainText;
use Ritmo\Admin\Components\Money;
use Ritmo\Admin\Components\Percent;
use Ritmo\Admin\Reports\ReportsServices;
use Ritmo\Admin\Reports\RunLineText;
use Ritmo\Admin\Screens\AlertText;
use Ritmo\Application\Ai\RequestData;
use Ritmo\Domain\Ai\DraftContext;
use Ritmo\Domain\Ai\RuleCatalogue;
use Ritmo\Domain\Config\FieldType;
use Ritmo\Domain\Ai\WeeklyFacts;
use Ritmo\Domain\Config\RuleSet;
use Ritmo\Domain\Model\Property;
use Ritmo\Domain\Support\Days;
use Ritmo\Domain\Support\Num;
use Ritmo\WordPress\Services;

/**
 * What each question of the assistant is built from, read from the plugin's own data and written
 * in the reader's language: the property's rooms and calendar for a request, the weekly panel's
 * facts, a run's report lines and counts, an alert's sentence and facts. Never a guest field.
 */
final class AiData
{
    private function __construct()
    {
    }

    /**
     * The language the assistant answers in: the site's.
     */
    public static function locale(): string
    {
        $locale = (string) get_locale();

        return preg_match('/^[a-z]{2,3}(_[A-Z]{2})?$/', $locale) === 1 ? $locale : 'en_US';
    }

    public static function property(string $id): ?Property
    {
        $properties = Services::instance()->properties();

        return ($id === '' ? null : $properties->find($id)) ?? ($properties->all()[0] ?? null);
    }

    /**
     * @return array{data: array<string, mixed>, context: DraftContext}
     */
    public static function request(Property $property, string $text): array
    {
        $services = Services::instance();
        $rules = $services->ruleSets()->current() ?? RuleSet::defaults();

        $names = [];
        foreach (RuleCatalogue::forRules()->fields() as $field) {
            if ($field->type === FieldType::Boolean || $field->type === FieldType::Choice) {
                $names[$field->key] = [DomainText::t($field->label)];
            }
        }

        return RequestData::of($property, $services->roomTypes()->forProperty($property->id), $rules, $services->clock()->today($property->timezone), $text, $names);
    }

    /**
     * @return array{about: array<string, string>, facts: list<array{label: string, value: string}>}
     */
    public static function weekly(Property $property): array
    {
        $reports = ReportsServices::instance();
        $report = $reports->reports()->weekly($reports->scope($property), Services::instance()->clock()->now());
        $currency = $property->currency;
        $facts = WeeklyFacts::formatted(WeeklyFacts::of($report, $property->id), static fn (int|float|string $value, string $kind): string => match ($kind) {
            WeeklyFacts::MONEY => Money::format((float) $value, $currency),
            WeeklyFacts::SHARE => Percent::share(Num::roundHalfEven((float) $value * 100, 4)),
            WeeklyFacts::COUNT => number_format_i18n((float) $value),
            default => (string) $value,
        });

        return ['about' => ['report' => 'weekly panel', 'property' => $property->name, 'currency' => $currency], 'facts' => $facts];
    }

    /**
     * @return array{about: array<string, string|int>, facts: list<array{label: string, value: string}>, property: string}|null
     */
    public static function run(int $runId): ?array
    {
        $run = Services::instance()->runs()->find($runId);
        if ($run === null) {
            return null;
        }
        $facts = [];
        foreach (RunLineText::ofSummary($run['summary'])['lines'] as $index => $line) {
            $facts[] = ['label' => 'Report line ' . ($index + 1), 'value' => $line];
        }
        foreach ($run['summary'] as $section => $values) {
            if (!is_array($values) || in_array($section, ['report', 'undo'], true)) {
                continue;
            }
            foreach ($values as $key => $value) {
                if (is_string($key) && (is_int($value) || is_float($value))) {
                    $facts[] = ['label' => $section . ' ' . str_replace('_', ' ', $key), 'value' => number_format_i18n((float) $value, is_int($value) ? 0 : 2)];
                }
            }
        }

        return [
            'about' => ['report' => 'run report', 'run' => $runId, 'kind' => $run['kind'], 'mode' => $run['mode']->value, 'status' => $run['status'], 'started' => Days::key($run['started_at'])],
            'facts' => array_slice($facts, 0, 60),
            'property' => $run['property_id'],
        ];
    }

    /**
     * @return array{alert: array<string, mixed>, property: string}|null
     */
    public static function alert(int $alertId): ?array
    {
        $services = Services::instance();
        $alert = $services->alerts()->find($alertId);
        if ($alert === null) {
            return null;
        }
        $rooms = [];
        foreach ($services->roomTypes()->forProperty($alert['property_id']) as $room) {
            $rooms[$room->code] = $room->name;
        }
        $facts = [];
        foreach (AlertText::FACTS as $key) {
            $value = $alert['context'][$key] ?? null;
            if (is_int($value) || is_float($value) || (is_string($value) && mb_strlen($value) <= 120)) {
                $facts[(string) (AlertText::factLabel($key) ?? $key)] = $value;
            }
        }

        return ['alert' => [
            'code' => $alert['code'],
            'severity' => $alert['severity'],
            'title' => AlertText::title($alert['code'], $alert['context']),
            'meaning' => AlertText::explain($alert['code']),
            'suggested' => AlertText::action($alert['code']),
            'summary' => mb_substr($alert['message'], 0, 300),
            'room' => $alert['room_code'] === '' ? '' : $alert['room_code'] . ' (' . ($rooms[$alert['room_code']] ?? '') . ')',
            'room_label' => $rooms[$alert['room_code']] ?? '',
            'night' => $alert['night'] === null ? '' : Days::key($alert['night']),
            'facts' => $facts,
        ], 'property' => $alert['property_id']];
    }
}
