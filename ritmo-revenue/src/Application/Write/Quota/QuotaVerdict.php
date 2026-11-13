<?php

declare(strict_types=1);

namespace Ritmo\Application\Write\Quota;

use DateTimeImmutable;

/**
 * Whether a listing may be recalculated now, and why not.
 * Reference: the dict of cota_recalculo.situacao (motives mapped to the BLOCK_* codes).
 */
final class QuotaVerdict
{
    public const ROOM_QUOTA_SPENT = 'room_quota_spent';
    public const ACCOUNT_MINUTE_LIMIT = 'account_minute_limit';
    public const ACCOUNT_429_WAIT = 'account_429_wait';
    public const ROOM_429_THIS_RUN = 'room_429_this_run';
    public const ROOM_UPSTREAM_UNAVAILABLE_THIS_RUN = 'room_upstream_unavailable_this_run';
    public const ROOM_FAILURE_THIS_RUN = 'room_failure_this_run';

    /**
     * @param list<string>       $blockedBy the codes above, in the reference's order
     * @param array<string, int> $byResult  outcome value => attempts of the listing in the window
     */
    public function __construct(
        public readonly bool $allowed,
        public readonly array $blockedBy,
        public readonly int $used,
        public readonly int $left,
        public readonly array $byResult,
        public readonly ?DateTimeImmutable $roomSlotOpensAt,
        public readonly ?DateTimeImmutable $accountSlotOpensAt,
        public readonly ?DateTimeImmutable $account429WaitUntil,
        public readonly int $accountLastMinute,
        public readonly bool $morningSlotWarning,
        public readonly DateTimeImmutable $runStart,
    ) {
    }
}
