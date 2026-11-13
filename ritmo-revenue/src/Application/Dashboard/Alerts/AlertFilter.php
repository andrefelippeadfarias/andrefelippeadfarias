<?php

declare(strict_types=1);

namespace Ritmo\Application\Dashboard\Alerts;

use DateTimeImmutable;

/**
 * The filters of the Alerts screen: severity, status, kind group, property and the date range
 * of when the alert was first seen. An empty value does not filter.
 */
final class AlertFilter
{
    public const STATUSES = ['unresolved', 'open', 'acknowledged', 'resolved', 'all'];

    public const SEVERITIES = ['critical', 'warning', 'info'];

    /** Codes of each kind group (ALERTS.md 2). */
    public const GROUPS = [
        'security' => ['security_unknown_origin', 'security_cancellation_burst', 'security_zero_value', 'security_zero_value_modified'],
        'bookings' => ['booking_below_expected', 'overbooked', 'sold_mismatch'],
        'inventory' => ['inventory_closed_without_booking', 'inventory_stay_off_rule', 'inventory_arrival_off_rule', 'push_disabled', 'push_stale'],
        'writing' => ['write_failed', 'recalc_quota_exhausted', 'manual_change_detected', 'listing_changed_manually'],
        'connections' => ['connector_down'],
        'schedule' => ['run_late', 'run_missed', 'scheduler_stalled', 'vigilance_gap'],
        'approvals' => ['approval_pending', 'approval_expired', 'mode_demoted'],
    ];

    public function __construct(
        public readonly string $propertyId = '',
        public readonly string $status = 'unresolved',
        public readonly string $severity = '',
        public readonly string $group = '',
        public readonly ?DateTimeImmutable $from = null,
        public readonly ?DateTimeImmutable $to = null,
    ) {
    }

    /**
     * @return list<string> the codes of the group; empty for every code
     */
    public function codes(): array
    {
        return self::GROUPS[$this->group] ?? [];
    }

    /**
     * The values that keep the filter in the URL of the next page.
     *
     * @return array<string, string>
     */
    public function query(): array
    {
        return array_filter([
            'status' => $this->status === 'unresolved' ? '' : $this->status,
            'severity' => $this->severity,
            'group' => $this->group,
            'from' => $this->from?->format('Y-m-d') ?? '',
            'to' => $this->to?->format('Y-m-d') ?? '',
        ], static fn (string $v): bool => $v !== '');
    }
}
