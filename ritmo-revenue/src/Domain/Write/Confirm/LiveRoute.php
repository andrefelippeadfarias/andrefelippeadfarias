<?php

declare(strict_types=1);

namespace Ritmo\Domain\Write\Confirm;

use DateTimeImmutable;

/**
 * How the live level of a room's writes can be checked now.
 */
enum LiveRoute: string
{
    /** The calendar was recalculated after the write: compare it as it is (no quota). */
    case CompareNow = 'compare_now';

    /** Ask for a recalculation first: the quota allows one. */
    case Recalculate = 'recalculate';

    /** No recalculation fits the quota: "not confirmed (recalculation limit)"; a later run confirms. */
    case NotConfirmedQuota = 'not_confirmed_quota';

    /**
     * A write usually triggers a recalculation within seconds but not always, so a recalculation
     * is spent only when the calendar is older than the write.
     *
     * Reference: SKILL.md (confirmation), AP 14/10 and 15/10.
     */
    public static function for(DateTimeImmutable $writtenAt, ?DateTimeImmutable $lastRecalculatedAt, bool $quotaAllows): self
    {
        if ($lastRecalculatedAt !== null && $lastRecalculatedAt >= $writtenAt) {
            return self::CompareNow;
        }

        return $quotaAllows ? self::Recalculate : self::NotConfirmedQuota;
    }
}
