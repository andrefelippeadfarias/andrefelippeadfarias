<?php

declare(strict_types=1);

namespace Ritmo\Application\Approval;

use DateTimeImmutable;
use Ritmo\Domain\Plan\ValidatedPlan;
use Ritmo\Domain\Plan\Validator;
use Ritmo\Domain\Support\Clock;
use Ritmo\Domain\Support\Days;
use Ritmo\Domain\Write\ApprovalConfig;
use Ritmo\Infrastructure\Db\Repository\PlanApprovalRepository;

/**
 * Plans waiting for a person: proposed from a validated plan, approved in full or in part (whole
 * groups) or rejected by a user who holds the approval capability at that moment, expired when
 * the day turns or the calendar gets old, superseded by a newer plan of the property.
 *
 * Listing values (minimum, base, maximum) always go to a request of their own and must be named
 * one by one: "approve all" does not reach them (task rule 9(h)). Nothing here writes: an
 * approval becomes an ApprovalGrant, which the approved write uses with fresh reads, the Validator
 * and the write gate.
 *
 * Reference: PLANO "Modos por propriedade"; operations spec, section 6.
 */
final class ApprovalService
{
    public const SUPERSEDED = 'superseded';
    public const DAY_OR_CALENDAR = 'day_turned_or_calendar_old';

    public function __construct(
        private readonly PlanApprovalRepository $repository,
        private readonly CapabilityCheck $capabilities,
        private readonly Clock $clock,
        private readonly ApprovalConfig $config = new ApprovalConfig(),
    ) {
    }

    /**
     * Stores a validated plan for approval: one request for its date rules and, when it changes
     * listing values, one more for them. Older pending requests of the same kind are superseded.
     *
     * @param DateTimeImmutable $calendarReadAt when the oldest calendar of the plan's rooms was read
     *
     * @return list<int> the ids of the requests created
     *
     * @throws ApprovalRefused when the plan was not sealed by the Validator of this process
     */
    public function propose(ValidatedPlan $plan, ?int $runId, DateTimeImmutable $calendarReadAt, ?int $rulesetVersion = null, ApprovalKind $kind = ApprovalKind::Plan): array
    {
        if (!Validator::sealed($plan)) {
            throw new ApprovalRefused(ApprovalRefused::UNSEALED_PLAN, 'Only a plan sealed by the Validator can be proposed.');
        }
        if ($kind === ApprovalKind::Listing) {
            throw new ApprovalRefused(ApprovalRefused::LISTING_NEEDS_EXPLICIT_APPROVAL, 'Listing values are split from the plan by the service.');
        }
        $propertyId = $plan->plan->propertyId;
        $this->expireDue($propertyId);
        $entries = PlanEntries::of($plan->plan, $plan->bases);
        $nights = array_values(array_filter($entries, static fn (PlanEntry $e): bool => $e->kind !== PlanEntry::LISTING));
        $listing = array_values(array_filter($entries, static fn (PlanEntry $e): bool => $e->kind === PlanEntry::LISTING));
        $expires = ApprovalWindow::expiresAt($plan->today, $plan->timezone, $calendarReadAt, $this->config->maxCalendarAgeHours);
        $ids = [];
        foreach ([[$kind, $nights], [ApprovalKind::Listing, $listing]] as [$requestKind, $requestEntries]) {
            if ($requestEntries === []) {
                continue;
            }
            foreach ($this->repository->pending($propertyId, $requestKind->value) as $older) {
                $this->repository->close($older['id'], ApprovalStatus::Superseded->value, self::SUPERSEDED);
            }
            $this->lapseApproved($propertyId, $requestKind->value, self::SUPERSEDED);
            $ids[] = $this->repository->create(
                $runId,
                $propertyId,
                $requestKind->value,
                PlanEntries::hash($requestEntries),
                $plan->today,
                $rulesetVersion,
                $calendarReadAt,
                $expires,
                array_map(static fn (PlanEntry $e): array => $e->toArray(), $requestEntries),
                $this->clock->now(),
            );
        }

        return $ids;
    }

    /**
     * A person approves the whole request (null) or the entries named (closed over their groups).
     * A change of the listing's minimum, base or maximum is approved only by name, by a person
     * allowed to change safety limits, with its new amount typed ($confirmations).
     *
     * @param list<string>|null     $selected      entry keys; null approves everything (not for listing values)
     * @param array<string, string> $confirmations listing entry key => the amount the person typed
     *
     * @throws ApprovalRefused when the request is unknown, not pending, expired, the user lacks the
     *                         capability, the selection is empty or names an unknown entry, or listing
     *                         values are approved without naming them, without the safety capability
     *                         or without their typed amount
     */
    public function approve(int $id, int $userId, ?array $selected = null, string $note = '', array $confirmations = []): ApprovalGrant
    {
        $row = $this->pendingRow($id, $userId);
        $entries = self::entries($row);
        $listing = array_filter($entries, static fn (PlanEntry $e): bool => $e->kind === PlanEntry::LISTING);
        if ($selected === null && ($row['kind'] === ApprovalKind::Listing->value || $listing !== [])) {
            throw new ApprovalRefused(ApprovalRefused::LISTING_NEEDS_EXPLICIT_APPROVAL, 'Each listing value must be approved by name.');
        }
        $selection = PartialApproval::close($entries, $selected ?? array_map(static fn (PlanEntry $e): string => $e->key(), $entries));
        if ($selection->approved === []) {
            throw new ApprovalRefused(ApprovalRefused::NOTHING_SELECTED, 'Nothing was selected.');
        }
        $this->checkListing(array_values(array_filter($entries, static fn (PlanEntry $e): bool => in_array($e->key(), $selection->approved, true))), $userId, $confirmations);
        $now = $this->clock->now();
        if (!$this->repository->decide($id, ApprovalStatus::Approved->value, $selection->approved, $selection->rejected, $userId, CapabilityCheck::APPROVE, $now, $note)) {
            throw new ApprovalRefused(ApprovalRefused::NOT_PENDING, 'The request was decided meanwhile.');
        }
        $this->lapseApproved((string) $row['property_id'], (string) $row['kind'], self::SUPERSEDED, $id);

        return $this->grantOf($this->repository->find($id) ?? $row);
    }

    /**
     * @throws ApprovalRefused as approve()
     */
    public function reject(int $id, int $userId, string $note = ''): void
    {
        $row = $this->pendingRow($id, $userId);
        $keys = array_map(static fn (PlanEntry $e): string => $e->key(), self::entries($row));
        if (!$this->repository->decide($id, ApprovalStatus::Rejected->value, [], $keys, $userId, CapabilityCheck::APPROVE, $this->clock->now(), $note)) {
            throw new ApprovalRefused(ApprovalRefused::NOT_PENDING, 'The request was decided meanwhile.');
        }
    }

    /**
     * The approval of a request, for the approved write: still in time, and the approver still
     * holds the capability now.
     *
     * @throws ApprovalRefused when it is not approved, expired or the approver lost the capability
     */
    public function grant(int $id): ApprovalGrant
    {
        $row = $this->repository->find($id);
        if ($row === null || $row['status'] !== ApprovalStatus::Approved->value) {
            throw new ApprovalRefused(ApprovalRefused::NOT_APPROVED, 'The request is not approved.');
        }
        if ($this->clock->now() >= $row['expires_at']) {
            $this->repository->finish($id, ApprovalStatus::Approved->value, ApprovalStatus::Lapsed->value, self::DAY_OR_CALENDAR, null);
            throw new ApprovalRefused(ApprovalRefused::EXPIRED, 'The approval expired before the write ran.');
        }
        if (!$this->capabilities->userCan((int) $row['decided_by'], CapabilityCheck::APPROVE)) {
            throw new ApprovalRefused(ApprovalRefused::NO_CAPABILITY, 'The approver no longer holds the approval capability.');
        }
        $grant = $this->grantOf($row);
        if ($grant->listingChanges() !== [] && !$this->capabilities->userCan((int) $row['decided_by'], CapabilityCheck::MANAGE_SAFETY)) {
            throw new ApprovalRefused(ApprovalRefused::LISTING_NEEDS_SAFETY, 'The approver of a listing change no longer holds the safety capability.');
        }

        return $grant;
    }

    /**
     * Every listing value approved: by a person allowed to change safety limits, with the new
     * amount typed.
     *
     * @param list<PlanEntry>       $approved
     * @param array<string, string> $confirmations
     *
     * @throws ApprovalRefused
     */
    private function checkListing(array $approved, int $userId, array $confirmations): void
    {
        foreach ($approved as $entry) {
            if ($entry->kind !== PlanEntry::LISTING) {
                continue;
            }
            if (!$this->capabilities->userCan($userId, CapabilityCheck::MANAGE_SAFETY)) {
                throw new ApprovalRefused(ApprovalRefused::LISTING_NEEDS_SAFETY, 'Only a person allowed to change safety limits approves a change of the listing.');
            }
            $typed = str_replace(',', '.', trim($confirmations[$entry->key()] ?? ''));
            $value = (float) ($entry->fields['value'] ?? 0);
            if (preg_match('/^\d{1,9}(\.\d{1,2})?$/', $typed) !== 1 || abs((float) $typed - $value) >= 0.005) {
                throw new ApprovalRefused(ApprovalRefused::LISTING_NOT_CONFIRMED, 'Type the new amount of each listing change to approve it.');
            }
        }
    }

    /**
     * The entries of a request, to show them or to approve them by name.
     *
     * @return list<PlanEntry>
     *
     * @throws ApprovalRefused when there is no such request
     */
    public function entriesOf(int $id): array
    {
        $row = $this->repository->find($id);
        if ($row === null) {
            throw new ApprovalRefused(ApprovalRefused::NOT_FOUND, 'No such request.');
        }

        return self::entries($row);
    }

    /**
     * The approved write ran.
     */
    public function markWritten(int $id, int $writeRunId): bool
    {
        return $this->repository->finish($id, ApprovalStatus::Approved->value, ApprovalStatus::Written->value, '', $writeRunId);
    }

    /**
     * Ends the pending requests of a property whose time is over.
     *
     * @return int requests expired
     */
    public function expireDue(string $propertyId): int
    {
        $now = $this->clock->now();
        $expired = 0;
        foreach ($this->repository->pending($propertyId) as $row) {
            if ($now >= $row['expires_at']) {
                $expired += $this->repository->close($row['id'], ApprovalStatus::Expired->value, self::DAY_OR_CALENDAR) ? 1 : 0;
            }
        }

        return $expired;
    }

    /**
     * Ends every open request of a property for a reason (the mode changed, the emergency switch
     * was engaged, the rules changed): the pending ones expire and the approved ones not written
     * yet lapse, so an approval given before never writes after the change.
     *
     * @return int requests ended
     */
    public function expireAll(string $propertyId, string $reason): int
    {
        $ended = 0;
        foreach ($this->repository->pending($propertyId) as $row) {
            $ended += $this->repository->close($row['id'], ApprovalStatus::Expired->value, $reason) ? 1 : 0;
        }

        return $ended + $this->lapseApproved($propertyId, null, $reason);
    }

    /**
     * Lapses the approved requests not written yet (of one kind, when given), but $keep.
     *
     * @return int requests lapsed
     */
    private function lapseApproved(string $propertyId, ?string $kind, string $reason, ?int $keep = null): int
    {
        $lapsed = 0;
        foreach ($this->repository->approved($propertyId, $kind) as $row) {
            if ($row['id'] !== $keep) {
                $lapsed += $this->repository->finish($row['id'], ApprovalStatus::Approved->value, ApprovalStatus::Lapsed->value, $reason, null) ? 1 : 0;
            }
        }

        return $lapsed;
    }

    /**
     * @return array<string, mixed>
     *
     * @throws ApprovalRefused
     */
    private function pendingRow(int $id, int $userId): array
    {
        $row = $this->repository->find($id);
        if ($row === null) {
            throw new ApprovalRefused(ApprovalRefused::NOT_FOUND, 'No such request.');
        }
        if (!$this->capabilities->userCan($userId, CapabilityCheck::APPROVE)) {
            throw new ApprovalRefused(ApprovalRefused::NO_CAPABILITY, 'Approving needs the approval capability.');
        }
        if ($row['status'] !== ApprovalStatus::Pending->value) {
            throw new ApprovalRefused(ApprovalRefused::NOT_PENDING, sprintf('The request is %s.', $row['status']));
        }
        if ($this->clock->now() >= $row['expires_at']) {
            $this->repository->close($id, ApprovalStatus::Expired->value, self::DAY_OR_CALENDAR);
            throw new ApprovalRefused(ApprovalRefused::EXPIRED, 'The request expired: the day turned or its calendar is old.');
        }

        return $row;
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return list<PlanEntry>
     */
    private static function entries(array $row): array
    {
        return array_values(array_map(static fn (array $e): PlanEntry => PlanEntry::fromArray($e), $row['entries']));
    }

    /**
     * @param array<string, mixed> $row
     */
    private function grantOf(array $row): ApprovalGrant
    {
        $approved = array_flip(array_map('strval', $row['approved_keys']));

        return new ApprovalGrant(
            (int) $row['id'],
            (string) $row['property_id'],
            ApprovalKind::from((string) $row['kind']),
            (string) $row['plan_hash'],
            Days::normalize($row['plan_day']),
            (int) $row['decided_by'],
            (string) $row['capability'],
            $row['decided_at'],
            $row['expires_at'],
            array_values(array_filter(self::entries($row), static fn (PlanEntry $e): bool => isset($approved[$e->key()]))),
        );
    }
}
