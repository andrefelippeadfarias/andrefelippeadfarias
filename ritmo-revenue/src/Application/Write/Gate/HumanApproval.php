<?php

declare(strict_types=1);

namespace Ritmo\Application\Write\Gate;

use DateTimeImmutable;
use Ritmo\Domain\Model\PropertyMode;
use Ritmo\Domain\Support\Days;

/**
 * A person approved exactly one plan (by its hash) of one day of the property. Valid when the
 * property is not in shadow mode, the licence allows writing, the plan asking for the token has
 * the same hash, today is the approval's day, and the approver holds the approval capability now.
 * Listing changes are allowed only when named here ("room code|field").
 */
final class HumanApproval implements WriteAuthorization
{
    /**
     * @param list<string> $listingChanges "room code|field" of every listing change approved
     */
    public function __construct(
        public readonly string $id,
        public readonly string $propertyId,
        public readonly string $planHash,
        public readonly DateTimeImmutable $today,
        public readonly int $approvedBy,
        public readonly DateTimeImmutable $approvedAt,
        public readonly array $listingChanges = [],
    ) {
    }

    public function refusal(WriteContext $context, string $planHash, DateTimeImmutable $today): ?string
    {
        return match (true) {
            $context->property->id !== $this->propertyId => WriteRefused::WRONG_PROPERTY,
            $context->property->mode === PropertyMode::Shadow => WriteRefused::MODE_SHADOW,
            !$context->licenceAllowsWrites => WriteRefused::LICENCE,
            !hash_equals($this->planHash, $planHash) => WriteRefused::APPROVAL_MISMATCH,
            Days::key($this->today) !== Days::key($today) => WriteRefused::APPROVAL_STALE,
            !$context->userCanApprove($this->approvedBy) => WriteRefused::APPROVER_LACKS_CAPABILITY,
            default => null,
        };
    }

    public function isHuman(): bool
    {
        return true;
    }

    public function allowsListingChange(string $roomCode, string $field): bool
    {
        return in_array($roomCode . '|' . $field, $this->listingChanges, true);
    }

    public function actor(): string
    {
        return 'user:' . $this->approvedBy;
    }
}
