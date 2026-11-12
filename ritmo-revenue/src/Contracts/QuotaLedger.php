<?php

declare(strict_types=1);

namespace Ritmo\Contracts;

use DateTimeImmutable;

/**
 * Persistent count of metered calls to external systems (for example recalculations per
 * listing per day). A call is recorded when it is attempted: a failed call still spends quota.
 */
interface QuotaLedger
{
    /**
     * Records one metered call.
     *
     * @param string            $provider  connector id ("pricelabs")
     * @param string            $action    metered operation ("recalculation")
     * @param string            $scope     what the quota is counted for (a listing ref, or "" for the account)
     * @param bool              $succeeded outcome of the call; failures count too
     * @param DateTimeImmutable $at        instant in UTC of the call
     */
    public function record(string $provider, string $action, string $scope, bool $succeeded, DateTimeImmutable $at): void;

    /**
     * Number of calls recorded at or after $since, successful or not.
     *
     * @param DateTimeImmutable $since instant in UTC
     */
    public function countSince(string $provider, string $action, string $scope, DateTimeImmutable $since): int;
}
