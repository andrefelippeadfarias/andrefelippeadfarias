<?php

declare(strict_types=1);

namespace Ritmo\Admin\Ai;

use Ritmo\Admin\Components\Capability;
use Ritmo\Admin\Components\DomainText;
use Ritmo\Admin\Components\Input;
use Ritmo\Admin\Components\Money;
use Ritmo\Admin\Components\NightDate;
use Ritmo\Admin\Components\Tab;
use Ritmo\Admin\Editors\EditorPage;
use Ritmo\Application\Ai\AiSettingsStore;
use Ritmo\Domain\Ai\AiDraft;
use Ritmo\Domain\Ai\AiEffort;
use Ritmo\Domain\Ai\AiModel;
use Ritmo\Domain\Ai\AiSettings;
use Ritmo\Domain\Ai\PriceTable;
use Ritmo\Domain\Ai\RuleChange;
use Ritmo\Domain\Support\Days;
use Ritmo\Domain\Support\Num;

/**
 * The "Assistant" screen: Ask (a request becomes a draft for a person; the weekly report in
 * words), Settings (key, model, effort, fallback, monthly cap, timeout, prices, connection test)
 * and Usage (the month's estimated spend against the cap, without any content). Renders
 * templates/admin/ai/*.
 */
final class AiScreen
{
    public const SLUG = 'ritmo-revenue-assistant';

    private function __construct()
    {
    }

    /**
     * @return list<Tab>
     */
    public static function tabs(): array
    {
        return [
            new Tab('ask', __('Ask', 'ritmo-revenue'), AiCapability::USE_AI),
            new Tab('settings', __('Settings', 'ritmo-revenue'), Capability::MANAGE_CONNECTIONS),
            new Tab('usage', __('Usage and cost', 'ritmo-revenue'), AiCapability::USE_AI),
        ];
    }

    public static function render(): void
    {
        $page = EditorPage::open(self::SLUG, __('Assistant', 'ritmo-revenue'), Capability::VIEW, self::tabs());
        $services = AiServices::instance();
        $status = $services->assistant()->status();
        $common = [
            'blocker' => $status->blocker() === null ? '' : AiWords::reason($status->blocker()),
            'ready' => $status->isReady(),
            'may_settings' => Capability::can(Capability::MANAGE_CONNECTIONS),
            'settings_url' => $page->url(['tab' => 'settings']),
        ];
        match ($page->tab) {
            'settings' => $page->render('admin/ai/settings', $common + self::settings($status->settings, $status->hasKey)),
            'usage' => $page->render('admin/ai/usage', $common + self::usage()),
            'ask' => $page->render('admin/ai/ask', $common + self::ask($page)),
            default => EditorPage::forbid(),
        };
    }

    /**
     * @return array<string, mixed>
     */
    private static function ask(EditorPage $page): array
    {
        $services = AiServices::instance();
        $drafts = [];
        foreach ($page->property === null ? [] : $services->drafts()->pending($page->property->id) as $row) {
            $drafts[] = self::draftRow($row['id'], $row['request'], $row['created_at'], $row['draft'], $page->roomNames(), $page->currency());
        }

        return [
            'property_id' => $page->property?->id ?? '',
            'drafts' => $drafts,
            'result' => AiResult::take(),
            'may_open' => Capability::can(Capability::MANAGE_RULES),
            'request' => Input::query()->text('request', 1000),
            'rest_url' => rest_url('ritmo/v1/ai/'),
            'rest_nonce' => wp_create_nonce('wp_rest'),
            'examples' => [
                __('Raise the Saturdays of December by 10% in the suite', 'ritmo-revenue'),
                __('Do not sell below 900 on the Christmas holiday', 'ritmo-revenue'),
                __('Set the occupancy goal of the next 7 days to 70%', 'ritmo-revenue'),
            ],
        ];
    }

    /**
     * A pending draft as the screen shows it: what it would do, in words, never as JSON.
     *
     * @param array<string, string> $roomNames
     *
     * @return array<string, mixed>
     */
    public static function draftRow(string $id, string $request, string $createdAt, AiDraft $draft, array $roomNames, string $currency): array
    {
        $lines = [];
        if ($draft->order !== null) {
            $order = $draft->order;
            $rooms = $order->rooms === ['*'] ? __('every room', 'ritmo-revenue') : implode(', ', array_map(static fn (string $c): string => $roomNames[$c] ?? $c, $order->rooms));
            /* translators: %s: rooms */
            $lines[] = sprintf(__('Rooms: %s', 'ritmo-revenue'), $rooms);
            $lines[] = $order->nights === []
                /* translators: 1: first night, 2: last night */
                ? sprintf(__('Nights: from %1$s to %2$s', 'ritmo-revenue'), NightDate::short(Days::date($order->from)), NightDate::short(Days::date($order->to)))
                /* translators: %s: list of nights */
                : sprintf(__('Nights: %s', 'ritmo-revenue'), implode(', ', array_map(static fn (string $n): string => NightDate::short(Days::date($n)), $order->nights)));
            foreach ($order->floors as $room => $amount) {
                /* translators: 1: room, 2: amount */
                $lines[] = sprintf(__('Minimum price for %1$s: %2$s', 'ritmo-revenue'), $roomNames[$room] ?? ($room === '*' ? __('every room', 'ritmo-revenue') : $room), Money::format((float) $amount, $currency));
            }
            foreach ($order->derivation as $row) {
                /* translators: 1: room, 2: base price, 3: percentage, 4: computed amount */
                $lines[] = sprintf(__('%1$s: base %2$s changed by %3$s%% gives %4$s (computed by the plugin)', 'ritmo-revenue'), $roomNames[$row['room']] ?? $row['room'], Money::format($row['base'], $currency), number_format_i18n($row['percent'], Num::roundHalfEven($row['percent'], 0) === $row['percent'] ? 0 : 2), Money::format($row['floor'], $currency));
            }
            if ($order->percentile !== null) {
                /* translators: %d: a market percentile */
                $lines[] = sprintf(__('Market percentile: %d', 'ritmo-revenue'), $order->percentile);
            }
        }
        foreach ($draft->ruleChanges as $change) {
            $lines[] = self::changeLine($change);
        }

        return [
            'id' => $id,
            'kind' => $draft->kind,
            'kind_text' => AiWords::kind($draft->kind),
            'summary' => $draft->summary,
            'request' => $request,
            'lines' => $lines,
            'questions' => $draft->questions,
            'created' => $createdAt,
        ];
    }

    private static function changeLine(RuleChange $change): string
    {
        $show = static fn (mixed $v): string => match (true) {
            $v === null => __('not set', 'ritmo-revenue'),
            is_bool($v) => $v ? __('yes', 'ritmo-revenue') : __('no', 'ritmo-revenue'),
            is_int($v), is_float($v) => number_format_i18n((float) $v, is_float($v) && Num::roundHalfEven($v, 0) !== $v ? 2 : 0),
            default => (string) $v,
        };

        /* translators: 1: name of the setting, 2: its value now, 3: the proposed value */
        return sprintf(__('%1$s: from %2$s to %3$s', 'ritmo-revenue'), DomainText::t($change->label) . ' (' . $change->path . ')', $show($change->before), $show($change->after));
    }

    /**
     * @return array<string, mixed>
     */
    private static function settings(AiSettings $settings, bool $hasKey): array
    {
        $vault = AiServices::instance()->services()->vault();
        $saved = $vault->status(AiSettingsStore::SECRET);
        $prices = [];
        foreach ($settings->priceTable()->toArray() as $model => $row) {
            $prices[] = ['model' => $model, 'name' => $model === PriceTable::OTHER ? __('Any other model (such as a fallback)', 'ritmo-revenue') : AiWords::model($model)] + $row;
        }

        return [
            'enabled' => $settings->enabled,
            'has_key' => $hasKey,
            /* translators: %s: date and time */
            'key_saved' => $hasKey ? ($saved->updatedAt === null ? __('A key is saved.', 'ritmo-revenue') : sprintf(__('A key is saved (since %s).', 'ritmo-revenue'), NightDate::instant($saved->updatedAt, wp_timezone_string()))) : __('No key is saved.', 'ritmo-revenue'),
            'vault_problem' => $vault->problem() !== null,
            'models' => array_map(static fn (string $id): array => ['id' => $id, 'name' => AiWords::model($id), 'available' => $settings->availableModels === [] || in_array($id, $settings->availableModels, true)], AiModel::CHOICES),
            'model' => $settings->model,
            'model_available' => $settings->modelAvailable(),
            'tested' => $settings->availableModels !== [],
            'efforts' => array_map(static fn (AiEffort $e): array => ['id' => $e->value, 'name' => AiWords::effort($e)], AiEffort::cases()),
            'effort' => $settings->effort->value,
            'fallback' => $settings->fallback,
            'cap' => $settings->monthlyCapUsd,
            'timeout' => $settings->timeoutSeconds,
            'prices' => $prices,
            'rest_url' => rest_url('ritmo/v1/ai/'),
            'rest_nonce' => wp_create_nonce('wp_rest'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function usage(): array
    {
        $services = AiServices::instance();
        $settings = $services->settings()->settings();
        $month = $services->usage()->current();
        $previous = $services->usage()->previous();
        $calls = [];
        foreach (array_reverse(array_slice($month->calls, -20)) as $call) {
            $calls[] = [
                'at' => self::localTime((string) $call['at']),
                'purpose' => AiWords::purpose((string) $call['purpose']),
                'model' => (string) $call['model'],
                'tokens' => number_format_i18n((float) (($call['tokens']['input'] ?? 0) + ($call['tokens']['cache_read'] ?? 0) + ($call['tokens']['cache_write'] ?? 0))) . ' / ' . number_format_i18n((float) ($call['tokens']['output'] ?? 0)),
                'cost' => AiWords::dollars((float) $call['cost_usd'], 4),
                'fallback' => (bool) $call['fallback'],
                'stop' => (string) $call['stop'],
            ];
        }
        $spent = $month->spentUsd();

        return [
            'month' => self::monthName($month->month),
            'spent' => AiWords::dollars($spent),
            'cap' => AiWords::dollars($settings->monthlyCapUsd),
            'share' => $settings->monthlyCapUsd > 0 ? min(100, (int) Num::roundHalfEven($spent / $settings->monthlyCapUsd * 100, 0)) : 0,
            'cap_reached' => $month->capReached($settings->monthlyCapUsd),
            'totals' => [
                'calls' => number_format_i18n((float) $month->totals['calls']),
                'input' => number_format_i18n((float) ($month->totals['input'] + $month->totals['cache_read'] + $month->totals['cache_write'])),
                'cached' => number_format_i18n((float) $month->totals['cache_read']),
                'output' => number_format_i18n((float) $month->totals['output']),
                'fallbacks' => number_format_i18n((float) $month->totals['fallbacks']),
                'refusals' => number_format_i18n((float) $month->totals['refusals']),
            ],
            'calls' => $calls,
            'previous' => $previous === null ? null : ['month' => self::monthName((string) $previous['month']), 'spent' => AiWords::dollars((float) ($previous['totals']['cost_usd'] ?? 0)), 'calls' => number_format_i18n((float) ($previous['totals']['calls'] ?? 0))],
        ];
    }

    /**
     * "2026-11" as the site's month name ("November 2026", "novembro de 2026").
     */
    private static function monthName(string $month): string
    {
        if (preg_match('/^\d{4}-\d{2}$/', $month) !== 1) {
            return $month;
        }
        $first = strtotime($month . '-15T12:00:00Z');

        return $first === false ? $month : (string) wp_date(_x('F Y', 'month and year', 'ritmo-revenue'), $first);
    }

    /**
     * A stored UTC instant in the site's time zone and date format.
     */
    private static function localTime(string $at): string
    {
        $time = strtotime($at);

        return $time === false ? $at : (string) wp_date(get_option('date_format') . ' ' . get_option('time_format'), $time);
    }
}
