<?php

declare(strict_types=1);

namespace Ritmo\Infrastructure\Http;

use DateTimeImmutable;
use InvalidArgumentException;
use Ritmo\Contracts\QuotaLedger;
use Ritmo\Infrastructure\Db\Repository\QuotaLogRepository;

/**
 * QuotaLedger kept in the database, so a quota holds across runs and processes.
 *
 * Quotas are counted over a rolling window ("3 per listing in any 24 hours"), never per
 * calendar day, and every attempt counts, including the ones that failed.
 *
 * One recorded call serves two counts: read with its scope it counts for that listing; read
 * with the scope "" it counts for the whole account, together with the calls of every scope.
 */
final class DbQuotaLedger implements QuotaLedger
{
    public function __construct(private readonly QuotaLogRepository $log)
    {
    }

    public function record(string $provider, string $action, string $scope, bool $succeeded, DateTimeImmutable $at): void
    {
        $this->log->add($provider, $action, $scope, $succeeded, $at);
    }

    public function countSince(string $provider, string $action, string $scope, DateTimeImmutable $since): int
    {
        return count($this->log->instants($provider, $action, $scope, $since));
    }

    /**
     * Calls in the rolling window that ends at $now: the ones made less than $windowSeconds ago.
     */
    public function countInWindow(string $provider, string $action, string $scope, int $windowSeconds, DateTimeImmutable $now): int
    {
        return count($this->window($provider, $action, $scope, $windowSeconds, $now));
    }

    /**
     * When the next call fits under a limit of $limit calls per $windowSeconds: the instant
     * the call that keeps the window full leaves it. Null when a call fits now.
     *
     * @throws InvalidArgumentException when the limit is below 1
     */
    public function opensAt(string $provider, string $action, string $scope, int $limit, int $windowSeconds, DateTimeImmutable $now): ?DateTimeImmutable
    {
        if ($limit < 1) {
            throw new InvalidArgumentException('A quota allows at least one call.');
        }

        $calls = $this->window($provider, $action, $scope, $windowSeconds, $now);
        if (count($calls) < $limit) {
            return null;
        }

        return $calls[count($calls) - $limit]->modify(sprintf('+%d seconds', $windowSeconds));
    }

    /**
     * @return list<DateTimeImmutable> oldest first
     */
    private function window(string $provider, string $action, string $scope, int $windowSeconds, DateTimeImmutable $now): array
    {
        if ($windowSeconds < 1) {
            throw new InvalidArgumentException('A quota window lasts at least one second.');
        }

        return $this->log->instants($provider, $action, $scope, $now->modify(sprintf('-%d seconds', $windowSeconds)), false);
    }
}
