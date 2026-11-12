<?php

declare(strict_types=1);

namespace Ritmo\Tests\Fakes;

use DateTimeImmutable;
use Ritmo\Contracts\QuotaLedger;

/**
 * A QuotaLedger in memory, with the semantics of the real one: a call recorded with a scope
 * also counts for the account (scope '').
 */
final class MemoryQuotaLedger implements QuotaLedger
{
    /** @var list<array{provider: string, action: string, scope: string, succeeded: bool, at: DateTimeImmutable}> */
    private array $calls = [];

    public function record(string $provider, string $action, string $scope, bool $succeeded, DateTimeImmutable $at): void
    {
        $this->calls[] = ['provider' => $provider, 'action' => $action, 'scope' => $scope, 'succeeded' => $succeeded, 'at' => $at];
    }

    public function countSince(string $provider, string $action, string $scope, DateTimeImmutable $since): int
    {
        return count(array_filter(
            $this->calls,
            static fn (array $call): bool => $call['provider'] === $provider
                && $call['action'] === $action
                && ($scope === '' || $call['scope'] === $scope)
                && $call['at'] >= $since
        ));
    }

    /**
     * @return list<array{provider: string, action: string, scope: string, succeeded: bool, at: DateTimeImmutable}>
     */
    public function calls(): array
    {
        return $this->calls;
    }
}
