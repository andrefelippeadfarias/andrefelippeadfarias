<?php

declare(strict_types=1);

namespace Ritmo\Admin\Screens;

use DateTimeImmutable;
use Ritmo\Admin\Components\Capability;
use Ritmo\Admin\Components\ErrorText;
use Ritmo\Admin\Components\Input;
use Ritmo\Admin\Components\Labels;
use Ritmo\Admin\Components\Menu;
use Ritmo\Admin\Components\Money;
use Ritmo\Admin\Components\NightDate;
use Ritmo\Admin\Components\Paging;
use Ritmo\Application\Dashboard\Alerts\AlertFilter;
use Ritmo\Domain\Model\Property;
use Ritmo\Domain\Support\Days;
use Ritmo\Infrastructure\Schedule\WpRecipientDirectory;
use Throwable;

/**
 * Alerts (admin spec 5.10): the filtered list, and one alert opened with what happened, its facts
 * in words, what to do and its history; acknowledging and resolving by capability.
 */
final class AlertsScreen
{
    public const PER_PAGE = 50;

    /** Codes only approvers acknowledge (ALERTS.md 4). */
    private const APPROVER_ACK = ['security_', 'write_failed'];

    private function __construct()
    {
    }

    public static function render(): void
    {
        $page = Page::open(Menu::ALERTS, __('Alerts', 'ritmo-revenue'), Capability::VIEW);
        $property = $page->property;
        $query = Input::query();
        $filter = new AlertFilter(
            $property?->id ?? '',
            $query->choice('status', AlertFilter::STATUSES, 'unresolved'),
            $query->choice('severity', AlertFilter::SEVERITIES, ''),
            $query->choice('group', array_keys(AlertFilter::GROUPS), ''),
            $query->date('from'),
            $query->date('to'),
        );
        $list = ScreenServices::instance()->alerts();
        try {
            $paging = new Paging($query->int('paged', 1, 1, 100000), self::PER_PAGE, $list->count($filter));
            $rows = $list->page($filter, $paging->offset(), self::PER_PAGE);
            $opened = $query->int('alert', 0, 0);
            $detail = $opened > 0 ? $list->find($opened) : null;
        } catch (Throwable $failure) {
            $page->render('alerts', ['error' => ErrorText::from($failure)]);

            return;
        }
        $timezone = $property?->timezone ?? 'UTC';
        $page->render('alerts', [
            'error' => null,
            'filter' => $filter->query() + ['status_value' => $filter->status],
            'severities' => array_combine(AlertFilter::SEVERITIES, array_map(Labels::severity(...), AlertFilter::SEVERITIES)),
            'groups' => self::groups(),
            'rows' => array_map(static fn (array $a): array => self::row($a, $timezone, $page), $rows),
            'detail' => $detail === null || ($property !== null && $detail['property_id'] !== '' && $detail['property_id'] !== $property->id) ? null : self::detail($detail, $property, $timezone),
            'paging' => [
                'label' => $paging->label(),
                'previous' => $paging->hasPrevious() ? $page->url(['paged' => $paging->page - 1] + $filter->query()) : '',
                'next' => $paging->hasNext() ? $page->url(['paged' => $paging->page + 1] + $filter->query()) : '',
            ],
            'clear' => $page->url(),
            'email' => get_user_meta(get_current_user_id(), WpRecipientDirectory::OPT_OUT_META, true) !== 'no',
            'property_id' => $property?->id ?? '',
            'hold' => $property === null ? false : ScreenServices::instance()->services()->stack()->holds()->open($property->id) !== null,
            'can_approve' => Capability::can(Capability::APPROVE),
        ]);
    }

    /**
     * @return array<string, string>
     */
    private static function groups(): array
    {
        return [
            'security' => __('Security', 'ritmo-revenue'),
            'bookings' => __('Bookings', 'ritmo-revenue'),
            'inventory' => __('Calendar', 'ritmo-revenue'),
            'writing' => __('Writing', 'ritmo-revenue'),
            'connections' => __('Connections', 'ritmo-revenue'),
            'schedule' => __('Schedule', 'ritmo-revenue'),
            'approvals' => __('Approvals', 'ritmo-revenue'),
        ];
    }

    /**
     * @param array<string, mixed> $alert
     *
     * @return array<string, mixed>
     */
    private static function row(array $alert, string $timezone, Page $page): array
    {
        return [
            'id' => $alert['id'],
            'severity' => Labels::severity($alert['severity']),
            'tone' => self::tone($alert['severity']),
            'title' => AlertText::title($alert['code'], $alert['context']),
            'where' => trim($alert['room_code'] . ($alert['night'] === null ? '' : ' · ' . NightDate::short($alert['night']))),
            'first' => NightDate::instant($alert['first_seen'], $timezone),
            'last' => NightDate::instant($alert['last_seen'], $timezone),
            'occurrences' => number_format_i18n($alert['occurrences']),
            'status' => Labels::alertStatus($alert['status']),
            'url' => $page->url(['alert' => $alert['id']]) . '#ritmo-alert-detail',
        ];
    }

    /**
     * @param array<string, mixed> $alert
     *
     * @return array<string, mixed>
     */
    private static function detail(array $alert, ?Property $property, string $timezone): array
    {
        $approverOnly = array_filter(self::APPROVER_ACK, static fn (string $p): bool => str_starts_with($alert['code'], $p)) !== [];
        $history = [[
            'when' => NightDate::instant($alert['first_seen'], $timezone),
            'text' => __('Raised', 'ritmo-revenue'),
        ]];
        if ($alert['occurrences'] > 1) {
            /* translators: %s: times */
            $history[] = ['when' => NightDate::instant($alert['last_seen'], $timezone), 'text' => sprintf(__('Seen again (%s times in all)', 'ritmo-revenue'), number_format_i18n($alert['occurrences']))];
        }
        if ($alert['acknowledged_at'] instanceof DateTimeImmutable) {
            /* translators: %s: name */
            $history[] = ['when' => NightDate::instant($alert['acknowledged_at'], $timezone), 'text' => sprintf(__('Acknowledged by %s', 'ritmo-revenue'), $alert['acknowledged_by'] === null ? __('someone', 'ritmo-revenue') : PeopleNames::name($alert['acknowledged_by']))];
        }
        if ($alert['resolved_at'] instanceof DateTimeImmutable) {
            $history[] = ['when' => NightDate::instant($alert['resolved_at'], $timezone), 'text' => $alert['resolved_by'] === null
                ? __('Resolved: the next run no longer found it', 'ritmo-revenue')
                /* translators: %s: name */
                : sprintf(__('Resolved by %s', 'ritmo-revenue'), PeopleNames::name($alert['resolved_by']))];
        }

        return [
            'id' => $alert['id'],
            'title' => AlertText::title($alert['code'], $alert['context']),
            'severity' => Labels::severity($alert['severity']),
            'tone' => self::tone($alert['severity']),
            'explain' => AlertText::explain($alert['code']),
            'action' => AlertText::action($alert['code']),
            'facts' => self::facts($alert, $property, $timezone),
            'history' => $history,
            'can_ack' => $alert['status'] === 'open' && (!$approverOnly || Capability::can(Capability::APPROVE)),
            'can_resolve' => $alert['status'] !== 'resolved' && Capability::can(Capability::APPROVE),
            'wave' => $alert['code'] === 'security_cancellation_burst',
            'night_url' => $alert['night'] === null || $alert['room_code'] === '' ? '' : Menu::url(Menu::NIGHTS, ['property' => $alert['property_id'], 'room' => $alert['room_code'], 'night' => Days::key($alert['night'])]),
        ];
    }

    /**
     * @param array<string, mixed> $alert
     *
     * @return list<array{label: string, value: string}>
     */
    private static function facts(array $alert, ?Property $property, string $timezone): array
    {
        $context = $alert['context'] + array_filter(['room' => $alert['room_code'], 'night' => $alert['night'] === null ? '' : Days::key($alert['night'])]);
        $facts = [];
        foreach (AlertText::FACTS as $key) {
            $label = AlertText::factLabel($key);
            $value = $context[$key] ?? null;
            if ($label === null || $value === null || $value === '' || is_array($value)) {
                continue;
            }
            $facts[] = ['label' => $label, 'value' => self::value($key, $value, $property, $timezone)];
        }

        return $facts;
    }

    private static function value(string $key, mixed $value, ?Property $property, string $timezone): string
    {
        if (is_bool($value)) {
            return $value ? __('yes', 'ritmo-revenue') : __('no', 'ritmo-revenue');
        }
        if (in_array($key, ['expected', 'value', 'before', 'after'], true) && is_numeric($value) && $property !== null) {
            return Money::format((float) $value, $property->currency);
        }
        if (is_string($value) && Days::isValid($value)) {
            return NightDate::short(Days::date($value));
        }
        if (is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}T/', $value) === 1) {
            return NightDate::instant(new DateTimeImmutable($value), $timezone);
        }
        if (is_string($value) && in_array($key, ['provider', 'mode', 'stage'], true)) {
            return match ($key) {
                'provider' => Labels::provider($value),
                'mode' => Labels::mode($value),
                default => Labels::runStage($value),
            };
        }

        return is_float($value) ? number_format_i18n($value, 2) : (is_int($value) ? number_format_i18n($value) : (string) $value);
    }

    private static function tone(string $severity): string
    {
        return match ($severity) {
            'critical' => 'critical',
            'warning' => 'attention',
            default => 'info',
        };
    }
}
