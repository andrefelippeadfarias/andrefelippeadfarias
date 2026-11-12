<?php

declare(strict_types=1);

namespace Ritmo\Domain\Relations\Coherence;

use DateTimeImmutable;

/**
 * Something a person should look at: a relation gave way to a floor, could not act, or
 * discarded another stage's work.
 */
final class RelationWarning
{
    /** The target's own floor is above the relation's ceiling: the ceiling was lifted to it. */
    public const CEILING_RAISED_TO_FLOOR = 'relation.ceiling_raised_to_floor';

    /** The target's floor is above the upper edge of its band: the target stays above the band. */
    public const FLOOR_ABOVE_BAND = 'relation.floor_above_band';

    /** A floor set by an owner order gave way to the lowest floor the room still has. */
    public const ORDER_FLOOR_YIELDED = 'relation.order_floor_yielded';

    /** The pace step of a band target was discarded on a night its reference is ladder-priced. */
    public const ENGINE_STEP_DISCARDED = 'relation.engine_step_discarded';

    /** An occupancy switch is triggered but the market has no data: the band ran instead. */
    public const SWITCH_NO_MARKET_DATA = 'relation.switch_no_market_data';

    /** The relation floor is above the room's price band ceiling: the ceiling follows the floor. */
    public const FLOOR_OVER_BAND_CEILING = 'relation.floor_over_band_ceiling';

    /**
     * @param array<string, scalar|null> $args
     */
    public function __construct(
        public readonly string $code,
        public readonly string $relationId,
        public readonly string $room,
        public readonly DateTimeImmutable $night,
        public readonly array $args = [],
    ) {
    }
}
