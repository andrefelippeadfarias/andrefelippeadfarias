<?php

declare(strict_types=1);

namespace Ritmo\Admin\Editors;

use OutOfBoundsException;

/**
 * Where each rule-set field is edited (spec 05, 5.5.2): its tab, its section of the general tab,
 * and whether it sits under "Advanced settings". By key prefix, outside the schema; a key that
 * matches no prefix fails loudly, so a new schema field is placed before it ships.
 */
final class RulesLayout
{
    public const GENERAL = 'general';
    public const SAFETY = 'safety';
    public const RELATIONS = 'relations';
    public const CALENDAR = 'calendar';

    /** Section id => key prefixes, in the order of the section list. */
    public const SECTIONS = [
        'goals' => ['occupancy'],
        'pace' => ['pace'],
        'floors' => ['floors', 'price_factors'],
        'ladder' => ['ladder'],
        'market' => ['market'],
        'soldout' => ['soldout', 'cadence', 'no_effect'],
        'stay' => ['stay'],
        'orders' => ['orders'],
        'plan' => ['plan', 'write', 'verify', 'approval', 'third_party', 'waves'],
        'runs' => ['schedule', 'light_run', 'alerts', 'modes'],
        'reports' => ['reports'],
    ];

    /** Keys edited outside the general tab, before the section prefixes are looked at. */
    private const TABS = [
        'safety' => self::SAFETY,
        'floors.fraction_of_reference_min' => self::SAFETY,
        'write.listing_changes_enabled' => self::SAFETY,
        'modes.autonomous_enabled' => self::SAFETY,
        'quota' => self::SAFETY,
        'relations' => self::RELATIONS,
        'price_bands' => self::RELATIONS,
        'calendar' => self::CALENDAR,
        'stay.seasons' => self::CALENDAR,
        'stay.packages' => self::CALENDAR,
    ];

    /** Keys (or prefixes) shown under "Advanced settings" of their section. */
    private const ADVANCED = [
        'relations.tolerance', 'relations.rounding_recheck', 'relations.scan_horizon_days', 'relations.audit_horizon_days',
        'write.max_dates_per_call', 'write.pms_max_ranges_per_post', 'write.call_timeout_seconds', 'write.max_read_age_minutes',
        'write.max_validation_age_minutes', 'write.token_ttl_minutes', 'write.max_identical_refusals', 'schedule.tick_budget_seconds',
        'schedule.run_budget_seconds', 'schedule.stalled_after_minutes', 'quota.account_wait_after_429_seconds', 'quota.future_slack_seconds',
        'plan.payload', 'plan.validator.calendar_max_age_hours', 'plan.validator.market_evidence_min_length', 'market.csv', 'market.retention_days',
    ];

    private function __construct()
    {
    }

    /**
     * @throws OutOfBoundsException when no tab or section takes the key
     */
    public static function place(string $key): Placement
    {
        $tab = self::GENERAL;
        foreach (self::TABS as $prefix => $placedTab) {
            if (self::under($key, $prefix)) {
                $tab = $placedTab;
                break;
            }
        }
        $section = $tab === self::GENERAL ? self::section($key) : $tab;

        return new Placement($tab, $section, self::advanced($key));
    }

    /**
     * Whether a field goes under "Advanced settings"; any key, also of the relation and band
     * forms, whose keys have no tab of their own.
     */
    public static function advanced(string $key): bool
    {
        foreach (self::ADVANCED as $prefix) {
            if (self::under($key, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The root keys a section edits, for a form of that section (a root key may be a sub-tree:
     * "stay" without its seasons and packages is the stay section).
     *
     * @return list<string>
     */
    public static function roots(string $section): array
    {
        return self::SECTIONS[$section] ?? [];
    }

    public static function under(string $key, string $prefix): bool
    {
        return $key === $prefix || str_starts_with($key, $prefix . '.');
    }

    private static function section(string $key): string
    {
        foreach (self::SECTIONS as $section => $prefixes) {
            foreach ($prefixes as $prefix) {
                if (self::under($key, $prefix)) {
                    return $section;
                }
            }
        }
        throw new OutOfBoundsException(sprintf('The field "%s" has no place in the rules screen.', $key));
    }
}
