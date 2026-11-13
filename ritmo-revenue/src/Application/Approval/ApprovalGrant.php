<?php

declare(strict_types=1);

namespace Ritmo\Application\Approval;

use DateTimeImmutable;

/**
 * A human approval as the write gate receives it: who approved which entries of which plan, for
 * which day, holding which capability, and until when. The gate re-checks it on every issue
 * (the approver still holds the capability, the day is the plan's, the approval has not expired)
 * and writes only the approved entries (ApprovedSubset).
 */
final class ApprovalGrant
{
    /**
     * @param list<PlanEntry> $approvedEntries
     */
    public function __construct(
        public readonly int $approvalId,
        public readonly string $propertyId,
        public readonly ApprovalKind $kind,
        public readonly string $planHash,
        public readonly DateTimeImmutable $planDay,
        public readonly int $approvedBy,
        public readonly string $capability,
        public readonly DateTimeImmutable $approvedAt,
        public readonly DateTimeImmutable $expiresAt,
        public readonly array $approvedEntries,
    ) {
    }

    /**
     * @return list<string>
     */
    public function approvedKeys(): array
    {
        return array_map(static fn (PlanEntry $e): string => $e->key(), $this->approvedEntries);
    }

    /**
     * The listing values approved by name, as "ROOM|field" (how the write gate names a listing change).
     *
     * @return list<string>
     */
    public function listingChanges(): array
    {
        return array_values(array_map(
            static fn (PlanEntry $e): string => $e->roomCode . '|' . $e->subject,
            array_filter($this->approvedEntries, static fn (PlanEntry $e): bool => $e->kind === PlanEntry::LISTING),
        ));
    }
}
