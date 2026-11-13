<?php

declare(strict_types=1);

namespace Ritmo\Tests\Unit\Application\Verify;

use Ritmo\Application\Approval\CapabilityCheck;

/**
 * Capabilities held by user id, changeable during a test.
 */
final class Capabilities implements CapabilityCheck
{
    /**
     * @param array<int, list<string>> $held user id => capabilities
     */
    public function __construct(private array $held = [])
    {
    }

    public function userCan(int $userId, string $capability): bool
    {
        return in_array($capability, $this->held[$userId] ?? [], true);
    }

    public function revoke(int $userId): void
    {
        unset($this->held[$userId]);
    }
}
