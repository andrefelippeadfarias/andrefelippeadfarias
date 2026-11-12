<?php

declare(strict_types=1);

namespace Ritmo\Application\ShadowRun;

/**
 * What became of an item of the plan in a shadow run, as stored in plan_items.status.
 */
final class PlanItemStatus
{
    /** Valid and different from what is in force: it would be written outside shadow mode. */
    public const WOULD_WRITE = 'would_write';

    /** Valid, but the percentages it would write are the ones already in force. */
    public const NO_CHANGE = 'no_change';

    /** The validator refused it; it would never be written. */
    public const REJECTED = 'rejected';

    private function __construct()
    {
    }
}
