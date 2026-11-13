<?php

declare(strict_types=1);

namespace Ritmo\Application\Rules;

use Ritmo\Application\Approval\ApprovalService;
use Ritmo\Infrastructure\Db\Repository\PlanApprovalRepository;

/**
 * PendingApprovals over the approval service of M3: the open requests of the repository and
 * ApprovalService::expireAll().
 */
final class ApprovalServiceAdapter implements PendingApprovals
{
    public function __construct(private readonly ApprovalService $approvals, private readonly PlanApprovalRepository $requests)
    {
    }

    public function waitingNights(string $propertyId): int
    {
        $nights = 0;
        foreach ($this->requests->pending($propertyId) as $row) {
            $nights += count((array) ($row['entries'] ?? []));
        }

        return $nights;
    }

    public function expireAll(string $propertyId, string $reason): int
    {
        return $this->approvals->expireAll($propertyId, $reason);
    }
}
