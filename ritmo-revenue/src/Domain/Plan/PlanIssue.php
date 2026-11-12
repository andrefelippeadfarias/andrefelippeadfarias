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

    /** The plan was computed for a day that is no longer today: it must be redone. */
    public const STALE_PLAN = 'stale_plan';
    public const UNKNOWN_ROOM = 'unknown_room';
    public const DUPLICATE_ITEM = 'duplicate_item';
    public const MISSING_REASON = 'missing_reason';
    public const PAST_DATE = 'past_date';
    /** The floor of tonight is above its current price. */
    public const RAISE_TODAY = 'raise_today';
    /** A floor for tonight without the current price: it cannot be shown not to raise it. */
    public const TODAY_WITHOUT_CURRENT_PRICE = 'today_without_current_price';
    public const BELOW_SAFETY_LIMIT = 'below_safety_limit';
    public const BELOW_HARD_FLOOR = 'below_hard_floor';
    public const BELOW_LISTING_MIN = 'below_listing_min';
    public const ABOVE_LISTING_MAX = 'above_listing_max';
    public const CEILING_BELOW_FLOOR = 'ceiling_below_floor';
    /** Everything the item sets is already in force. */
    public const NO_EFFECT = 'no_effect';

    /** Warning: the floor raises the price by more than one step of the pace engine. */
    public const RISE_ABOVE_MAX_STEP = 'rise_above_max_step';
    /** Warning: without what is in force, the validator cannot tell whether the item changes anything. */
    public const NO_BEFORE = 'no_before';

    /**
     * @param string                     $severity  self::ERROR or self::WARNING
     * @param int|null                   $itemIndex position of the item in the plan; null for an issue of the whole plan
     * @param array<string, scalar|null> $params    the numbers behind the issue
     */
    public function __construct(
        public readonly string $severity,
        public readonly string $code,
        public readonly string $message,
        public readonly ?int $itemIndex = null,
        public readonly ?string $roomCode = null,
        public readonly ?DateTimeImmutable $date = null,
        public readonly array $params = [],
    ) {
    }

    public function isError(): bool
    {
        return $this->severity === self::ERROR;
    }
}
