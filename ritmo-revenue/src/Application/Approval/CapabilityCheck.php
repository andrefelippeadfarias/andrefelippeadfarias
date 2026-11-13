<?php

declare(strict_types=1);

namespace Ritmo\Application\Approval;

/**
 * Whether a user holds a capability NOW (the WordPress layer answers with user_can()).
 */
interface CapabilityCheck
{
    /** Approve, partially approve or reject a plan; ask for an undo; release a hold or a freeze. */
    public const APPROVE = 'ritmo_approve';

    public function userCan(int $userId, string $capability): bool;
}
