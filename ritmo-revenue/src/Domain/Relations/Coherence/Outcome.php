<?php

declare(strict_types=1);

namespace Ritmo\Domain\Relations\Coherence;

/**
 * What a relation did on a night.
 */
enum Outcome: string
{
    /** The relation already holds: nothing written. */
    case Satisfied = 'satisfied';

    /** The target's floor or ceiling was set. */
    case Applied = 'applied';

    /** A price the relation needs is missing. */
    case NoData = 'no_data';

    /** No reference (or no member of the set) can be compared with. */
    case EmptySet = 'empty_set';

    case SkippedFrozen = 'skipped_frozen';
    case SkippedOwnerPrice = 'skipped_owner_price';
    case SkippedSoldOut = 'skipped_sold_out';

    /** The reference is on its last units and does not pull the target. */
    case SkippedReferenceLastUnits = 'skipped_reference_last_units';

    /** The target is sold out or closed in the PMS. */
    case SkippedTargetUnavailable = 'skipped_target_unavailable';

    /** A band that only lowers on this night type: the target is already low enough. */
    case SatisfiedDownOnly = 'satisfied_down_only';

    /** A band that only lowers: the target's floor does not let it go lower. */
    case FloorBlocksLowering = 'floor_blocks_lowering';

    /** The owner order the relation needs is not active for the room-night. */
    case NotAuthorised = 'not_authorised';

    /** An occupancy switch whose threshold is not reached. */
    case NotTriggered = 'not_triggered';

    /** An occupancy switch whose threshold is reached, without market data: the band runs. */
    case NoMarketData = 'no_market_data';

    /** The switch holds and moved the target. */
    case SwitchApplied = 'switch_applied';

    /**
     * The switch holds and the target is already where the switch puts it: the relation is
     * satisfied by the occupancy switch, with no price item. The validator accepts the night.
     */
    case SwitchSatisfied = 'switch_satisfied';

    /** The switch holds but the owner froze or fixed the target: nothing written. */
    case SwitchHeldByOwner = 'switch_held_by_owner';

    /** The switch holds the night: the relations it suspends do not run. */
    public function isSwitchHolding(): bool
    {
        return $this === self::SwitchApplied || $this === self::SwitchSatisfied || $this === self::SwitchHeldByOwner;
    }
}
