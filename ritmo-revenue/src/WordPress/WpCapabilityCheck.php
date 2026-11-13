<?php

declare(strict_types=1);

namespace Ritmo\WordPress;

use Ritmo\Application\Approval\CapabilityCheck;

/**
 * The capabilities of a user, as WordPress grants them now (user_can): an approver who lost the
 * capability since approving can no longer have the plan written.
 */
final class WpCapabilityCheck implements CapabilityCheck
{
    public function userCan(int $userId, string $capability): bool
    {
        return $userId > 0 && user_can($userId, $capability);
    }
}
