<?php

declare(strict_types=1);

namespace Ritmo\Admin\Components;

/**
 * One translated text per value of the plugin's enumerations, so no code reaches a screen.
 * A value the plugin does not know (data of a later version) reads "other", never the code.
 */
final class Labels
{
    private function __construct()
    {
    }

    public static function mode(string $mode): string
    {
        return match ($mode) {
            'shadow' => __('Shadow', 'ritmo-revenue'),
            'approval' => __('Approval', 'ritmo-revenue'),
            'auto' => __('Autonomous', 'ritmo-revenue'),
            default => self::other(),
        };
    }

    public static function modeSentence(string $mode): string
    {
        return match ($mode) {
            'shadow' => __('Shadow: the plugin reads and decides, but writes nothing.', 'ritmo-revenue'),
            'approval' => __('Approval: every change waits for a person before it is written.', 'ritmo-revenue'),
            'auto' => __('Autonomous: the plugin writes within the safety limits; listing changes still wait for a person.', 'ritmo-revenue'),
            default => self::other(),
        };
    }

    public static function runKind(string $kind): string
    {
        return match ($kind) {
            'shadow' => __('Shadow run', 'ritmo-revenue'),
            'full' => __('Full run', 'ritmo-revenue'),
            'light' => __('Light run', 'ritmo-revenue'),
            'retry' => __('Retry', 'ritmo-revenue'),
            'manual' => __('Run started by a person', 'ritmo-revenue'),
            'approved_write' => __('Approved write', 'ritmo-revenue'),
            'undo' => __('Undo', 'ritmo-revenue'),
            default => self::other(),
        };
    }

    public static function runStatus(string $status): string
    {
        return match ($status) {
            'running' => __('running', 'ritmo-revenue'),
            'completed' => __('completed', 'ritmo-revenue'),
            'failed' => __('failed', 'ritmo-revenue'),
            'stopped' => __('stopped', 'ritmo-revenue'),
            'refused' => __('refused', 'ritmo-revenue'),
            'busy' => __('waiting for another run', 'ritmo-revenue'),
            default => self::other(),
        };
    }

    public static function runStage(string $stage): string
    {
        return match ($stage) {
            'collect', 'collect_light' => __('Reading bookings and the calendar', 'ritmo-revenue'),
            'watch' => __('Checking for changes made by others', 'ritmo-revenue'),
            'measure' => __('Measuring occupancy', 'ritmo-revenue'),
            'decide' => __('Deciding prices', 'ritmo-revenue'),
            'validate' => __('Checking the plan against the safety rules', 'ritmo-revenue'),
            'shadow' => __('Writing nothing: shadow mode', 'ritmo-revenue'),
            'write' => __('Writing the approved changes', 'ritmo-revenue'),
            'verify' => __('Reading back what was written', 'ritmo-revenue'),
            'report' => __('Writing the report', 'ritmo-revenue'),
            '' => __('Starting', 'ritmo-revenue'),
            default => self::other(),
        };
    }

    public static function pace(?string $status): string
    {
        return match ($status) {
            'ahead' => __('ahead of pace', 'ritmo-revenue'),
            'on_pace' => __('on pace', 'ritmo-revenue'),
            'behind' => __('behind pace', 'ritmo-revenue'),
            'very_behind' => __('very behind pace', 'ritmo-revenue'),
            'floor' => __('held at the floor', 'ritmo-revenue'),
            null, '' => __('no pace measured', 'ritmo-revenue'),
            default => self::other(),
        };
    }

    /** The short symbol of a pace status, always shown next to its words. */
    public static function paceSymbol(?string $status): string
    {
        return match ($status) {
            'ahead' => '▲',
            'on_pace' => '●',
            'behind' => '▼',
            'very_behind' => '▼▼',
            'floor' => '■',
            default => '·',
        };
    }

    public static function severity(string $severity): string
    {
        return match ($severity) {
            'critical' => __('Critical', 'ritmo-revenue'),
            'warning' => __('Warning', 'ritmo-revenue'),
            'info' => __('Information', 'ritmo-revenue'),
            default => self::other(),
        };
    }

    public static function alertStatus(string $status): string
    {
        return match ($status) {
            'open' => __('open', 'ritmo-revenue'),
            'acknowledged' => __('acknowledged', 'ritmo-revenue'),
            'resolved' => __('resolved', 'ritmo-revenue'),
            default => self::other(),
        };
    }

    public static function approvalStatus(string $status): string
    {
        return match ($status) {
            'pending' => __('waiting for approval', 'ritmo-revenue'),
            'approved' => __('approved, being written', 'ritmo-revenue'),
            'rejected' => __('refused', 'ritmo-revenue'),
            'expired' => __('expired', 'ritmo-revenue'),
            'superseded' => __('replaced by a newer plan', 'ritmo-revenue'),
            'written' => __('written', 'ritmo-revenue'),
            'lapsed' => __('not written: the nights changed before the write', 'ritmo-revenue'),
            default => self::other(),
        };
    }

    public static function approvalKind(string $kind): string
    {
        return match ($kind) {
            'plan' => __('Plan', 'ritmo-revenue'),
            'listing' => __('Listing change', 'ritmo-revenue'),
            'undo' => __('Undo', 'ritmo-revenue'),
            default => self::other(),
        };
    }

    public static function provider(string $provider): string
    {
        return match ($provider) {
            'beds24', 'pms' => __('the PMS', 'ritmo-revenue'),
            'pricelabs', 'pricing' => __('the pricing system', 'ritmo-revenue'),
            'listing' => __('the listing', 'ritmo-revenue'),
            default => self::other(),
        };
    }

    /** What a change or a plan entry touches, in words. */
    public static function field(string $field): string
    {
        return match ($field) {
            'floor', 'floor_pct', 'min_price' => __('floor', 'ritmo-revenue'),
            'floor_fixed' => __('fixed floor', 'ritmo-revenue'),
            'ceiling', 'ceiling_pct', 'max_price' => __('ceiling', 'ritmo-revenue'),
            'ceiling_fixed' => __('fixed ceiling', 'ritmo-revenue'),
            'price', 'price_type' => __('fixed price', 'ritmo-revenue'),
            'expiry' => __('validity of the date rule', 'ritmo-revenue'),
            'min_stay', 'pms_min_stay' => __('minimum stay', 'ritmo-revenue'),
            'max_stay', 'pms_max_stay' => __('maximum stay', 'ritmo-revenue'),
            'check_in', 'check_in_mask', 'no_checkin' => __('arrival', 'ritmo-revenue'),
            'check_out', 'check_out_mask', 'no_checkout' => __('departure', 'ritmo-revenue'),
            'pms_restriction' => __('arrival and departure rule', 'ritmo-revenue'),
            'available', 'availability', 'pms_available' => __('availability', 'ritmo-revenue'),
            'rule', 'override', 'date_override' => __('date rule', 'ritmo-revenue'),
            'min', 'listing_min' => __('listing minimum', 'ritmo-revenue'),
            'base', 'listing_base' => __('listing base', 'ritmo-revenue'),
            'max', 'listing_max' => __('listing maximum', 'ritmo-revenue'),
            default => str_starts_with($field, 'pms_channel_limit') ? __('channel limit', 'ritmo-revenue') : self::other(),
        };
    }

    public static function nightType(?string $type): string
    {
        return match ($type) {
            'weekday' => __('weekday night', 'ritmo-revenue'),
            'weekend' => __('weekend night', 'ritmo-revenue'),
            'holiday' => __('holiday night', 'ritmo-revenue'),
            default => '',
        };
    }

    private static function other(): string
    {
        return __('other', 'ritmo-revenue');
    }
}
