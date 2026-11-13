<?php

declare(strict_types=1);

namespace Ritmo\Admin;

use Ritmo\Application\Approval\ApprovalRefused;
use Ritmo\Application\Mode\ModeRefused;

/**
 * The sentences a person reads when a change of mode or a decision on a plan is refused, through
 * the text domain (the use cases give a code and English sentences; the screens and the REST API
 * show these).
 */
final class RefusalText
{
    private function __construct()
    {
    }

    public static function mode(ModeRefused $refused): string
    {
        return match ($refused->reason) {
            ModeRefused::NO_CAPABILITY => __('You do not hold the capability this change of mode needs.', 'ritmo-revenue'),
            ModeRefused::DISABLED => __('The autonomous mode is switched off for this site.', 'ritmo-revenue'),
            ModeRefused::CONFIRMATION => __('Type the property\'s id to confirm the autonomous mode.', 'ritmo-revenue'),
            ModeRefused::CONDITIONS => $refused->conditions === []
                ? __('The property does not meet the conditions of this mode yet.', 'ritmo-revenue')
                : implode(' ', array_map(static fn (array $c): string => self::condition($c['code'], $c['params']), $refused->conditions)),
            default => __('This change of mode is not allowed now.', 'ritmo-revenue'),
        };
    }

    public static function approval(ApprovalRefused $refused): string
    {
        return match ($refused->reason) {
            ApprovalRefused::NOT_FOUND => __('There is no such plan.', 'ritmo-revenue'),
            ApprovalRefused::NOT_PENDING => __('The plan was already decided, or replaced by a newer one.', 'ritmo-revenue'),
            ApprovalRefused::NOT_APPROVED => __('The plan is not approved.', 'ritmo-revenue'),
            ApprovalRefused::NO_CAPABILITY => __('You do not hold the approval capability.', 'ritmo-revenue'),
            ApprovalRefused::EXPIRED => __('The plan expired: the day turned or its calendar is old. The next run proposes a new one.', 'ritmo-revenue'),
            ApprovalRefused::NOTHING_SELECTED => __('Nothing was selected.', 'ritmo-revenue'),
            ApprovalRefused::UNKNOWN_ENTRY => __('A night ticked is not part of this plan.', 'ritmo-revenue'),
            ApprovalRefused::LISTING_NEEDS_EXPLICIT_APPROVAL => __('Each listing value must be approved by name.', 'ritmo-revenue'),
            default => __('This decision is not allowed now.', 'ritmo-revenue'),
        };
    }

    /**
     * @param array<string, int|string> $params
     */
    public static function condition(string $code, array $params): string
    {
        $int = static fn (string $key): int => (int) ($params[$key] ?? 0);
        $text = static fn (string $key): string => (string) ($params[$key] ?? '');

        return match ($code) {
            'switch_engaged' => __('The emergency switch is engaged.', 'ritmo-revenue'),
            /* translators: %s: code of the alert */
            'alert_open' => sprintf(__('The alert %s is open: resolve it first.', 'ritmo-revenue'), $text('alert')),
            /* translators: 1: runs needed, 2: runs that completed */
            'shadow_runs' => sprintf(__('The last %1$d runs must have completed in shadow mode with a plan (%2$d did).', 'ritmo-revenue'), $int('needed'), $int('done')),
            'shadow_run_old' => __('The newest completed shadow run is older than 24 hours: run one first.', 'ritmo-revenue'),
            /* translators: %s: beds24 or pricelabs */
            'connection_untested' => sprintf(__('Test the %s connection first: it must have passed within 24 hours.', 'ritmo-revenue'), $text('provider')),
            'write_failed_open' => __('A write failed and its alert is open: resolve it first.', 'ritmo-revenue'),
            /* translators: 1: days, 2: date of the failure */
            'write_failed_recent' => sprintf(__('A write failed in the last %1$d days (%2$s).', 'ritmo-revenue'), $int('days'), $text('date')),
            'safety_confirmed' => __('A person allowed to change safety limits must confirm them first (Settings, setup).', 'ritmo-revenue'),
            'wave_hold' => __('A cancellation wave is holding price cuts: release it first.', 'ritmo-revenue'),
            'from_approval_only' => __('A property reaches the autonomous mode only from approval.', 'ritmo-revenue'),
            /* translators: 1: days needed, 2: plans needed, 3: days so far, 4: plans so far */
            'approval_time' => sprintf(__('The property needs %1$d days in approval and %2$d approved plans written first (%3$d days and %4$d plans so far).', 'ritmo-revenue'), $int('days_needed'), $int('plans_needed'), $int('days'), $int('plans')),
            default => __('A condition of this mode is not met.', 'ritmo-revenue'),
        };
    }
}
