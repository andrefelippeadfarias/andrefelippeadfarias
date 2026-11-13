<?php

declare(strict_types=1);

namespace Ritmo\Admin\Reports;

/**
 * Why a write was refused or stopped, in a few words (the gate's refusal codes and the executor's
 * stop reasons, as a run report line carries them).
 */
final class WriteReasonText
{
    private function __construct()
    {
    }

    public static function of(string $code): string
    {
        return match ($code) {
            'kill_switch' => __('the emergency switch is engaged', 'ritmo-revenue'),
            'mode_shadow', 'mode_not_autonomous' => __('the mode of the property does not allow it', 'ritmo-revenue'),
            'not_approved', 'approval_mismatch', 'approval_stale', 'approver_lacks_capability' => __('the plan was not approved as it is now', 'ritmo-revenue'),
            'stale_plan', 'stale_validation', 'stale_reads', 'base_changed' => __('the plan was older than the systems; the next run plans again', 'ritmo-revenue'),
            'raise_today_floor', 'raise_today_ceiling', 'raise_today_deletion', 'tonight_unknown' => __('tonight\'s price may never go up', 'ritmo-revenue'),
            'lock_not_held', 'lock_lost' => __('another run was writing', 'ritmo-revenue'),
            'nothing_to_write' => __('there was nothing to write', 'ritmo-revenue'),
            'listing_needs_approval' => __('a change of the listing values needs a person\'s approval', 'ritmo-revenue'),
            'day_turned' => __('the day turned at the property', 'ritmo-revenue'),
            'token_expired', 'time_budget' => __('the time for this run ran out', 'ritmo-revenue'),
            'rate_limited' => __('a system asked to slow down', 'ritmo-revenue'),
            'auth_failed' => __('a connection was refused; check its key', 'ritmo-revenue'),
            'rejected', 'upstream_error', 'not_ready', 'blocked', 'read_failed', 'unknown' => __('a system did not take the change', 'ritmo-revenue'),
            default => __('a safety check refused it', 'ritmo-revenue'),
        };
    }
}
