<?php

declare(strict_types=1);

namespace Ritmo\Domain\Pricing;

/**
 * Codes of the rules the pace engine applies. A decision carries the codes of the rules that
 * shaped it, each with its parameters (PaceDecision::$trace); the admin turns them into text.
 */
final class RuleCode
{
    /** The night: its type, the days left and the configured period it falls in. */
    public const NIGHT = 'night';

    /** Outcome: the night is before today. */
    public const PAST_NIGHT = 'past_night';

    /** Outcome: every unit is sold; protecting sold-out nights comes in a later milestone. */
    public const SOLD_OUT_PENDING_M2 = 'sold_out_pending_m2';

    /** Outcome: the night has no market anchor to aim at. */
    public const NO_MARKET_ANCHOR = 'no_market_anchor';

    /** The price read for the night was outside the date rule in force: the engine starts from the price the rule allows. */
    public const PRICE_WITHIN_RULE_IN_FORCE = 'price_within_rule_in_force';

    /** Where the night is against its expected sales curve. */
    public const PACE_STATUS = 'pace_status';

    /** The target: the market percentile of the status, as a public and as a sent price. */
    public const MARKET_TARGET = 'market_target';

    /** The anchor is small or old: the step is halved. */
    public const WEAK_ANCHOR = 'weak_anchor';

    /** A night selling ahead of its curve never goes down: the target is at least the current price. */
    public const AHEAD_NEVER_LOWERS = 'ahead_never_lowers';

    /** One step towards the target, limited by the days left. */
    public const STEP = 'step';

    /** The step would go under the hard floor: the price stops at the floor. */
    public const HELD_AT_FLOOR = 'held_at_floor';

    /** The price stops at the highest floor the listing maximum allows. */
    public const CAPPED_AT_LISTING_MAX = 'capped_at_listing_max';

    /** The price of tonight never goes up. */
    public const TODAY_NEVER_RISES = 'today_never_rises';

    /** Outcome: the change is within the tolerance, nothing to do. */
    public const WITHIN_TOLERANCE = 'within_tolerance';

    /** Outcome: the previous step in the same direction has not reached the sales channels yet. */
    public const CADENCE_PREVIOUS_STEP_NOT_PUSHED = 'cadence_previous_step_not_pushed';

    /** Outcome: there was already a step in the same direction in the last 24 hours. */
    public const CADENCE_STEP_WITHIN_24H = 'cadence_step_within_24h';

    /** Outcome: a new floor is proposed. */
    public const PACE_STEP = 'pace_step';

    /** Going up: the ceiling is set at the target so the pricing algorithm cannot jump past the step. */
    public const CEILING_AT_TARGET = 'ceiling_at_target';

    /** Going down with the current price held up by the algorithm or an old ceiling: the ceiling comes down too. */
    public const CEILING_FOLLOWS_FLOOR = 'ceiling_follows_floor';

    private function __construct()
    {
    }
}
