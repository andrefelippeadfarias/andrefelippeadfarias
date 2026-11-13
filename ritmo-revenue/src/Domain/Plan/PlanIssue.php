<?php

declare(strict_types=1);

namespace Ritmo\Domain\Plan;

use DateTimeImmutable;

/**
 * An error or a warning of the plan validator. Errors stop the plan; warnings ask a person to
 * look. The code identifies the rule; the message is an English sentence safe to show.
 */
final class PlanIssue
{
    public const ERROR = 'error';
    public const WARNING = 'warning';

    // The plan and its entries.
    /** The plan was computed for a day that is no longer today: it must be redone. */
    public const STALE_PLAN = 'stale_plan';
    public const UNKNOWN_ROOM = 'unknown_room';
    public const DUPLICATE_ITEM = 'duplicate_item';
    public const MISSING_REASON = 'missing_reason';
    public const PAST_DATE = 'past_date';
    /** Fresh calendar older than the configured hours under a decision that depends on occupancy. */
    public const STALE_CALENDAR = 'stale_calendar';
    /** Warning: the same, accepted explicitly for this validation. */
    public const STALE_CALENDAR_ACCEPTED = 'stale_calendar_accepted';

    // Tonight.
    /** The floor of tonight is above its current price. */
    public const RAISE_TODAY = 'raise_today';
    /** A floor for tonight without the current price: it cannot be shown not to raise it. */
    public const TODAY_WITHOUT_CURRENT_PRICE = 'today_without_current_price';

    // Floors, each family on its own (the most serious alone when under the safety limit).
    public const BELOW_SAFETY_LIMIT = 'below_safety_limit';
    public const BELOW_LISTING_MIN = 'below_listing_min';
    public const BELOW_OWNER_FLOOR = 'below_owner_floor';
    public const BELOW_QUALITY_FLOOR = 'below_quality_floor';
    public const BELOW_HOLIDAY_FLOOR = 'below_holiday_floor';
    /** Under the general holiday floor: no order releases it. */
    public const BELOW_HOLIDAY_SAFETY_FLOOR = 'below_holiday_safety_floor';
    /** Under the last-minute market floor of the strategy the item cites (or the strategy has none tonight). */
    public const BELOW_MARKET_FLOOR = 'below_market_floor';
    /** Warning: under an owner floor, released by the order the item cites. */
    public const BELOW_FLOOR_BY_ORDER = 'below_floor_by_order';
    /** Warning: under an owner floor, down to the last-minute market floor of the strategy the item cites. */
    public const BELOW_FLOOR_BY_MARKET_FLOOR = 'below_floor_by_market_floor';
    /** Warning: under the quality floor by an order in force. */
    public const BELOW_QUALITY_BY_ORDER = 'below_quality_by_order';
    /** Warning: under the quality floor with the market evidence written in the item. */
    public const BELOW_QUALITY_BY_MARKET = 'below_quality_by_market';
    public const ABOVE_LISTING_MAX = 'above_listing_max';
    public const CEILING_BELOW_FLOOR = 'ceiling_below_floor';
    /** A room that never takes a discount would be put under its current price. */
    public const NO_DISCOUNT_ROOM = 'no_discount_room';

    // Last-minute discount under the listing minimum.
    public const LAST_MINUTE_TOO_DEEP = 'last_minute_too_deep';
    public const LAST_MINUTE_WITHOUT_CALENDAR = 'last_minute_without_calendar';
    public const LAST_MINUTE_SOLD_OUT = 'last_minute_sold_out';
    public const LAST_MINUTE_TOO_SOLD = 'last_minute_too_sold';
    /** Warning: tonight is already mostly sold; the discount only follows the one in force. */
    public const LAST_MINUTE_TOO_SOLD_TONIGHT = 'last_minute_too_sold_tonight';
    public const LAST_MINUTE_LAST_UNIT_ONLY = 'last_minute_last_unit_only';

    // Owner orders.
    public const ORDER_NOT_IN_FORCE = 'order_not_in_force';
    public const ORDER_OUT_OF_SCOPE = 'order_out_of_scope';
    public const ORDER_DOES_NOT_RELEASE = 'order_does_not_release';
    /** A manual change of the owner other than the cited order freezes the night. */
    public const NIGHT_FROZEN = 'night_frozen';

    // Stay and orphan nights.
    public const STAY_BELOW_REQUIRED = 'stay_below_required';
    /** A stay above one night where the rule opens one night. */
    public const STAY_ABOVE_ONE_NIGHT = 'stay_above_one_night';
    /** Warning: a stay above the one the night requires. */
    public const STAY_ABOVE_REQUIRED = 'stay_above_required';
    public const STAY_OUT_OF_RANGE = 'stay_out_of_range';
    /** Warning: a closed day or holiday night written without its stay. */
    public const STAY_NOT_IN_ITEM = 'stay_not_in_item';
    public const ORPHAN_NOT_ALLOWED = 'orphan_not_allowed';
    public const ORPHAN_WITHOUT_PROOF = 'orphan_without_proof';
    /** The fresh calendar contradicts the orphan proof. */
    public const ORPHAN_CONTRADICTED = 'orphan_contradicted';

    // Arrival and departure days.
    public const MASK_ALL_CLOSED = 'mask_all_closed';
    public const MASK_OPENS_CLOSED_DAY = 'mask_opens_closed_day';
    public const MASK_BEYOND_ORPHAN_SIDE = 'mask_beyond_orphan_side';
    public const MASK_CLOSES_OPEN_NIGHT = 'mask_closes_open_night';
    public const MASK_CLOSES_OPEN_DAY = 'mask_closes_open_day';
    /** The reason of the date rule is kept while the item changes the price it explains. */
    public const KEEP_REASON_WITH_PRICE = 'keep_reason_with_price';

    // Discount bands of the relations, in currency after the rounding of the floors.
    public const BAND_ABOVE_REFERENCE = 'band_above_reference';
    public const BAND_CEILING_ABOVE_REFERENCE = 'band_ceiling_above_reference';
    public const BAND_ABOVE_UPPER = 'band_above_upper';
    public const BAND_BELOW_LOWER = 'band_below_lower';
    public const BAND_BELOW_BY_SWITCH = 'band_below_by_switch';
    public const BAND_BELOW_BY_ORDER = 'band_below_by_order';
    public const BAND_BELOW_CONFIRM_AFTER_RECALC = 'band_below_confirm_after_recalc';

    // Deletions and listing changes.
    /** Warning: deleting the date rule drops the stay the night requires. */
    public const DELETE_REMOVES_STAY = 'delete_removes_stay';
    /** Warning: deleting a holiday date rule drops its protection floor. */
    public const DELETE_REMOVES_HOLIDAY_FLOOR = 'delete_removes_holiday_floor';
    public const LISTING_NEEDS_ORDER = 'listing_needs_order';
    public const LISTING_WEEKLY_CHANGE_ABOVE_LIMIT = 'listing_weekly_change_above_limit';
    public const LISTING_BASE_BELOW_MIN = 'listing_base_below_min';

    // PMS changes (stay, arrival and departure).
    public const PMS_ROOM_UNKNOWN = 'pms_room_unknown';
    public const PMS_PAST_NIGHT = 'pms_past_night';
    public const PMS_BEYOND_HORIZON = 'pms_beyond_horizon';
    public const PMS_DUPLICATE = 'pms_duplicate';

    // Effect.
    /** Everything the item sets is already in force. */
    public const NO_EFFECT = 'no_effect';
    /** Warning: the floor raises the price by more than the configured rise. */
    public const RISE_ABOVE_MAX_STEP = 'rise_above_max_step';
    /** Warning: without what is in force, the validator cannot tell whether the item changes anything. */
    public const NO_BEFORE = 'no_before';

    /**
     * @param string                     $severity  self::ERROR or self::WARNING
     * @param int|null                   $itemIndex position of the entry in its list of the plan; null for the whole plan
     * @param array<string, scalar|null> $params    the numbers behind the issue
     * @param IssueSubject               $subject   what the index points at
     */
    public function __construct(
        public readonly string $severity,
        public readonly string $code,
        public readonly string $message,
        public readonly ?int $itemIndex = null,
        public readonly ?string $roomCode = null,
        public readonly ?DateTimeImmutable $date = null,
        public readonly array $params = [],
        public readonly IssueSubject $subject = IssueSubject::Item,
    ) {
    }

    public function isError(): bool
    {
        return $this->severity === self::ERROR;
    }
}
