<?php

declare(strict_types=1);

namespace Ritmo\Application\Verify;

use DateTimeImmutable;
use Ritmo\Application\Approval\ApprovalKind;
use Ritmo\Application\Approval\ApprovalRefused;
use Ritmo\Application\Approval\ApprovalService;
use Ritmo\Application\Approval\CapabilityCheck;
use Ritmo\Application\Approval\PlanEntry;
use Ritmo\Application\Approval\PlanEntries;
use Ritmo\Contracts\PmsConnector;
use Ritmo\Contracts\PricingConnector;
use Ritmo\Domain\Model\Property;
use Ritmo\Domain\Model\RoomType;
use Ritmo\Domain\Plan\IssueSubject;
use Ritmo\Domain\Plan\ValidationContext;
use Ritmo\Domain\Plan\Validator;
use Ritmo\Domain\Support\Clock;
use Ritmo\Domain\Write\Change\ChangeRecord;
use Ritmo\Domain\Write\Change\UndoPlanner;
use Ritmo\Domain\Write\State\ChangeField;
use Ritmo\Domain\Write\State\NightFields;
use Ritmo\Domain\Write\State\Provider;
use Ritmo\Infrastructure\Db\Repository\ChangeLogRepository;
use Ritmo\Infrastructure\Db\Repository\WrittenStepRepository;

/**
 * Undo, asked by a person who holds the approval capability: the change rows become a plan draft
 * built from what the nights hold NOW (a night changed since is left out, never overwritten), the
 * draft goes through the Validator (tonight never rises, safety floors, orders), and what passes
 * is proposed and approved by that person. It never writes: the approved write does, through the
 * write gate. Once the undo is confirmed stored, confirm() marks the rows and their steps undone.
 */
final class UndoService
{
    public function __construct(
        private readonly ChangeLogRepository $changes,
        private readonly WrittenStepRepository $steps,
        private readonly PricingConnector $pricing,
        private readonly PmsConnector $pms,
        private readonly ApprovalService $approvals,
        private readonly CapabilityCheck $capabilities,
        private readonly Clock $clock,
        private readonly UndoPlanner $planner = new UndoPlanner(),
    ) {
    }

    /**
     * @param array<string, RoomType> $rooms     room code => room type of the property
     * @param list<int>               $changeIds the change-log rows to undo
     *
     * @throws ApprovalRefused when the user lacks the approval capability
     */
    public function request(Property $property, array $rooms, array $changeIds, int $userId, Validator $validator, ValidationContext $context, ?int $runId = null): UndoRequest
    {
        if (!$this->capabilities->userCan($userId, CapabilityCheck::APPROVE)) {
            throw new ApprovalRefused(ApprovalRefused::NO_CAPABILITY, 'Undoing a change needs the approval capability.');
        }
        $records = array_values(array_filter($this->changes->byIds($changeIds), static fn (ChangeRecord $r): bool => $r->propertyId === $property->id));
        $draft = $this->planner->plan($records, $this->readNow($records, $rooms), $rooms, $property->id, $this->clock->today($property->timezone), $this->clock->now());
        $list = array_values($rooms);
        $result = $validator->validate($draft->plan, $property, $list, $context);
        $refused = $result->errors;
        if (!$result->isValid()) {
            $result = $validator->validate($draft->plan->without($result->rejectedItems(), $result->rejected(IssueSubject::Deletion), $result->rejected(IssueSubject::Listing)), $property, $list, $context);
            $refused = [...$refused, ...$result->errors];
        }
        $grants = [];
        if ($result->plan !== null && PlanEntries::of($result->plan->plan, $result->plan->bases) !== []) {
            foreach ($this->approvals->propose($result->plan, $runId, $this->clock->now(), null, ApprovalKind::Undo) as $id) {
                $keys = array_map(static fn (PlanEntry $e): string => $e->key(), $this->approvals->entriesOf($id));
                $grants[] = $this->approvals->approve($id, $userId, $keys, 'undo asked by the approver');
            }
        }

        return new UndoRequest($draft, $refused, $grants);
    }

    /**
     * The undo of these rows was written and confirmed stored: the rows, and the steps their
     * runs wrote on those nights, are undone (the cadence stops counting the steps).
     *
     * @param list<int> $changeIds
     *
     * @return int change rows marked
     */
    public function confirm(array $changeIds, DateTimeImmutable $at): int
    {
        $records = $this->changes->byIds($changeIds);
        foreach ($records as $record) {
            if ($record->runId !== null && $record->night !== null && $record->provider === Provider::Pricing) {
                $this->steps->markUndone($record->runId, $record->roomCode, $record->night, $at);
            }
        }

        return $this->changes->markUndone($changeIds, $at);
    }

    /**
     * What every night (and listing) of the rows holds now, by ChangeRecord::subjectKey().
     *
     * @param list<ChangeRecord>      $records
     * @param array<string, RoomType> $rooms
     *
     * @return array<string, NightFields>
     */
    private function readNow(array $records, array $rooms): array
    {
        $now = [];
        $listings = null;
        foreach ($records as $record) {
            $room = $rooms[$record->roomCode] ?? null;
            $key = $record->subjectKey();
            if ($room === null || isset($now[$key])) {
                continue;
            }
            if ($record->provider === Provider::Listing) {
                $listings ??= $this->pricing->listListings();
                foreach ($listings as $listing) {
                    if ($listing->ref === $room->pricingRef) {
                        $now[$key] = new NightFields([ChangeField::LISTING_MIN => $listing->min, ChangeField::LISTING_BASE => $listing->base, ChangeField::LISTING_MAX => $listing->max]);
                    }
                }
                continue;
            }
            $night = $record->night;
            $now[$key] = $record->provider === Provider::Pricing
                ? NightFields::fromOverride($this->pricing->readOverrides($room->pricingRef, $night, $night)[0] ?? null)
                : NightFields::fromInventory($this->pms->readInventory([$room->pmsRef], $night, $night)[$room->pmsRef][0] ?? null);
        }

        return $now;
    }
}
