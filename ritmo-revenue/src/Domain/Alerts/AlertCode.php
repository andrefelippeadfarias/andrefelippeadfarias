<?php

declare(strict_types=1);

namespace Ritmo\Domain\Alerts;

/**
 * The catalog of alerts (operations spec, section 5.1), with the default severity and the
 * lifecycle of each.
 */
enum AlertCode: string
{
    case SecurityUnknownOrigin = 'security_unknown_origin';
    case SecurityCancellationBurst = 'security_cancellation_burst';
    case SecurityZeroValue = 'security_zero_value';
    case SecurityZeroValueModified = 'security_zero_value_modified';
    case BookingBelowExpected = 'booking_below_expected';
    case Overbooked = 'overbooked';
    case SoldMismatch = 'sold_mismatch';
    case PushDisabled = 'push_disabled';
    case PushStale = 'push_stale';
    case InventoryClosedWithoutBooking = 'inventory_closed_without_booking';
    case InventoryStayOffRule = 'inventory_stay_off_rule';
    case InventoryArrivalOffRule = 'inventory_arrival_off_rule';
    case WriteFailed = 'write_failed';
    case RecalcQuotaExhausted = 'recalc_quota_exhausted';
    case ConnectorDown = 'connector_down';
    case ManualChangeDetected = 'manual_change_detected';
    case ListingChangedManually = 'listing_changed_manually';
    case ApprovalPending = 'approval_pending';
    case ApprovalExpired = 'approval_expired';
    case RunLate = 'run_late';
    case RunMissed = 'run_missed';
    case SchedulerStalled = 'scheduler_stalled';
    case VigilanceGap = 'vigilance_gap';
    case ModeDemoted = 'mode_demoted';
    /** The newest market price that covers the coming nights is older than market.stale_after_days. */
    case MarketStale = 'market_stale';
    /** An enabled sales ladder would price nights that have no value on the market ruler. */
    case MarketGap = 'market_gap';
    /** Steps written to the pricing system are not confirmed at the channel in time. */
    case DeliveryOverdue = 'delivery_overdue';
    /** The 12-month review is due again. */
    case ReviewDue = 'review_due';
    /** A run planned more PMS changes than stay.write.max_changes_per_run: none was written. */
    case PmsChangesCapped = 'pms_changes_capped';

    public function severity(): AlertSeverity
    {
        return match ($this) {
            self::SecurityUnknownOrigin, self::SecurityCancellationBurst, self::SecurityZeroValue, self::SecurityZeroValueModified,
            self::Overbooked, self::WriteFailed, self::ConnectorDown, self::ModeDemoted => AlertSeverity::Critical,
            self::ApprovalPending, self::ApprovalExpired, self::MarketGap, self::ReviewDue => AlertSeverity::Info,
            default => AlertSeverity::Warning,
        };
    }

    public function lifecycle(): AlertLifecycle
    {
        return match ($this) {
            self::Overbooked, self::SoldMismatch, self::PushDisabled, self::PushStale, self::InventoryClosedWithoutBooking,
            self::InventoryStayOffRule, self::InventoryArrivalOffRule, self::RecalcQuotaExhausted, self::ConnectorDown,
            self::ApprovalPending, self::RunLate, self::SchedulerStalled, self::MarketStale, self::MarketGap,
            self::DeliveryOverdue, self::ReviewDue, self::PmsChangesCapped => AlertLifecycle::Condition,
            default => AlertLifecycle::Event,
        };
    }

    /**
     * Security and write alerts are acknowledged and resolved by an approver; the others by any
     * user who sees them (operations spec 5.9).
     */
    public function needsApprover(): bool
    {
        return str_starts_with($this->value, 'security_') || $this === self::WriteFailed;
    }
}
