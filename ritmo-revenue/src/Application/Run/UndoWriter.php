<?php

declare(strict_types=1);

namespace Ritmo\Application\Run;

use Closure;
use DateTimeImmutable;
use OutOfBoundsException;
use Ritmo\Application\Approval\CapabilityCheck;
use Ritmo\Application\ConnectorSource;
use Ritmo\Application\EmergencySwitch;
use Ritmo\Application\Schedule\LockedRunLauncher;
use Ritmo\Application\Verify\RecordService;
use Ritmo\Application\Verify\UndoService;
use Ritmo\Application\Write\Execute\WriteExecutor;
use Ritmo\Application\Write\Execute\WriteRun;
use Ritmo\Application\Write\Gate\HumanApproval;
use Ritmo\Application\Write\Gate\PlanHash;
use Ritmo\Application\Write\Gate\TonightState;
use Ritmo\Application\Write\Gate\WriteContext;
use Ritmo\Application\Write\Gate\WriteGate;
use Ritmo\Application\Write\Gate\WriteRefused;
use Ritmo\Domain\Config\RuleSet;
use Ritmo\Domain\Model\Property;
use Ritmo\Domain\Model\PropertyMode;
use Ritmo\Domain\Model\RoomType;
use Ritmo\Domain\Plan\ValidationContext;
use Ritmo\Domain\Plan\Validator;
use Ritmo\Domain\Support\Clock;
use Ritmo\Infrastructure\Db\Repository\OperationEventRepository;
use Ritmo\Infrastructure\Db\Repository\OrderRepository;
use Ritmo\Infrastructure\Db\Repository\PropertyRepository;
use Ritmo\Infrastructure\Db\Repository\RoomTypeRepository;
use Ritmo\Infrastructure\Db\Repository\RunRepository;
use Ritmo\Infrastructure\Db\Repository\WriteOpRepository;
use Ritmo\Infrastructure\Lock\DbLock;
use Throwable;

/**
 * "Undo" on the change log (changelog-undo spec): the undo is a plan like any other. It reads
 * the nights again (a night that changed after the change is a conflict and is left alone),
 * goes through the Validator, is approved by the person who asked (who must hold the approval
 * capability), gets a token from the write gate for exactly that plan, is sent in series by the
 * executor and read back; only the nights read back as stored are recorded and marked undone.
 * It runs as a run of kind "undo" under the property's run lock; never under the emergency
 * switch, never for a property in shadow (there is nothing it wrote).
 */
final class UndoWriter
{
    public const KIND = 'undo';

    /** The refusal of the PMS rows of an undo: nothing writes a PMS value back yet (D9). */
    public const PMS_LEFT_OUT = 'pms_field';

    public function __construct(
        private readonly PropertyRepository $properties,
        private readonly RoomTypeRepository $roomTypes,
        private readonly RunRepository $runs,
        private readonly OrderRepository $orders,
        private readonly UndoService $undo,
        private readonly RecordService $record,
        private readonly WriteOpRepository $ops,
        private readonly OperationEventRepository $events,
        private readonly ConnectorSource $connectors,
        private readonly WriteGate $gate,
        private readonly WriteExecutor $executor,
        private readonly EmergencySwitch $switch,
        private readonly DbLock $locks,
        private readonly CapabilityCheck $capabilities,
        private readonly Clock $clock,
    ) {
    }

    /**
     * @param list<int> $changeIds the change-log rows to undo
     *
     * @return array{status: string, run_id: int|null, written: int, undone: int, refused: list<string>}
     *
     * @throws OutOfBoundsException                         when the property does not exist
     * @throws \Ritmo\Application\Approval\ApprovalRefused when the user lacks the approval capability
     */
    public function undo(string $propertyId, array $changeIds, int $userId, RuleSet $rules): array
    {
        $property = $this->properties->find($propertyId) ?? throw new OutOfBoundsException(sprintf('Unknown property "%s".', $propertyId));
        if ($this->switch->isEngaged() || $property->mode === PropertyMode::Shadow) {
            return ['status' => 'blocked', 'run_id' => null, 'written' => 0, 'undone' => 0, 'refused' => []];
        }
        $lock = $this->locks->acquire(LockedRunLauncher::lockKey($propertyId), LockedRunLauncher::LOCK_TTL_SECONDS);
        if ($lock === null) {
            return ['status' => 'busy', 'run_id' => null, 'written' => 0, 'undone' => 0, 'refused' => []];
        }
        $runId = $this->runs->start($propertyId, self::KIND, $property->mode, null, $this->clock->now(), $this->clock->today($property->timezone));
        try {
            $result = $this->write($property, $changeIds, $userId, $rules, $runId, fn (): bool => $this->locks->heartbeat($lock, LockedRunLauncher::LOCK_TTL_SECONDS));
            $this->runs->finish($runId, RunRepository::COMPLETED, ['undo' => $result], $this->clock->now());
        } catch (Throwable $failure) {
            $this->runs->finish($runId, RunRepository::FAILED, [], $this->clock->now(), str_starts_with($failure::class, 'Ritmo\\') ? $failure->getMessage() : 'The undo failed.');

            throw $failure;
        } finally {
            $this->locks->release($lock);
        }

        return ['status' => 'completed', 'run_id' => $runId] + $result;
    }

    /**
     * @param list<int>        $changeIds
     * @param Closure(): bool $heartbeat
     *
     * @return array{written: int, undone: int, refused: list<string>}
     */
    private function write(Property $property, array $changeIds, int $userId, RuleSet $rules, int $runId, Closure $heartbeat): array
    {
        $rooms = [];
        foreach ($this->roomTypes->forProperty($property->id) as $room) {
            $rooms[$room->code] = $room;
        }
        $rooms = $this->live($rooms);
        $today = $this->clock->today($property->timezone);
        $context = new ValidationContext(null, $this->orders->book($property->id, $today));
        [$changeIds, $pmsRows] = $this->undo->withoutPms($changeIds);
        $leftOut = $pmsRows === [] ? [] : [self::PMS_LEFT_OUT];
        if ($changeIds === []) {
            return ['written' => 0, 'undone' => 0, 'refused' => $leftOut];
        }
        $request = $this->undo->request($property, $rooms, $changeIds, $userId, new Validator($rules, $this->clock, $rules->plan()), $context, $runId);
        $refused = array_values(array_unique([...$leftOut, ...array_map(static fn ($issue): string => $issue->code, $request->refused)]));
        $grant = $request->grants[0] ?? null;
        $plan = $request->plan;
        if ($grant === null || $plan === null) {
            return ['written' => 0, 'undone' => 0, 'refused' => $refused];
        }
        $auth = new HumanApproval((string) $grant->approvalId, $property->id, PlanHash::of($plan->plan), $grant->planDay, $grant->approvedBy, $grant->approvedAt, $grant->listingChanges());
        try {
            $issued = $this->gate->issue($plan, $auth, new WriteContext(
                $property,
                array_values($rooms),
                $this->clock->now(),
                $this->tonight($rooms, $today),
                $heartbeat,
                $this->connectors->pricingPmsName(),
                true,
                fn (int $user): bool => $this->capabilities->userCan($user, CapabilityCheck::APPROVE),
            ));
        } catch (WriteRefused $refusal) {
            return ['written' => 0, 'undone' => 0, 'refused' => [...$refused, $refusal->reason]];
        }
        $this->executor->execute(new WriteRun($runId, $property, $plan, $issued, $this->connectors->pricing(), $this->connectors->pms(), $heartbeat, null, $this->clock->now()));
        $now = $this->clock->now();
        $confirmed = JournalNights::confirmed($this->ops->ofRun($runId), $now);
        $this->record->record($property->id, $confirmed, [], $plan->bases + array_map(static fn (RoomType $r): float => $r->base, $rooms), $auth->actor(), $runId, $now);
        $complete = $confirmed !== [] && count($confirmed) >= count($plan->plan->items) + count($plan->plan->deletions);
        $undone = $complete ? $this->undo->confirm($changeIds, $now) : 0;
        $this->events->record($property->id, OperationEventRepository::UNDO, 'requested', $complete ? 'written' : 'partial', sprintf('%d change(s) asked, %d undone.', count($changeIds), $undone), $auth->actor(), $now, $runId);

        return ['written' => count($confirmed), 'undone' => $undone, 'refused' => $refused];
    }

    /**
     * The rooms with the listing values the pricing system holds now: the percentages of the undo
     * are over the base it applies (RunContext does the same for a run).
     *
     * @param array<string, RoomType> $rooms
     *
     * @return array<string, RoomType>
     */
    private function live(array $rooms): array
    {
        $read = [];
        foreach ($this->connectors->pricing()->listListings() as $listing) {
            $read[$listing->ref] = $listing;
        }
        foreach ($rooms as $code => $room) {
            $listing = $read[$room->pricingRef] ?? null;
            if ($listing !== null) {
                $rooms[$code] = $room->withListing($listing->min, $listing->base, $listing->max) ?? $room;
            }
        }

        return $rooms;
    }

    /**
     * Tonight of every room, read now: the price the pricing system holds and the date rule.
     *
     * @param array<string, RoomType> $rooms
     *
     * @return array<string, TonightState>
     */
    private function tonight(array $rooms, DateTimeImmutable $today): array
    {
        $pricing = $this->connectors->pricing();
        $out = [];
        foreach ($rooms as $code => $room) {
            $price = $this->connectors->readsPricingCalendar() ? ($pricing->readPrices($room->pricingRef, $today, $today)[0] ?? null)?->price : null;
            $out[$code] = new TonightState($code, $price, $pricing->readOverrides($room->pricingRef, $today, $today)[0] ?? null);
        }

        return $out;
    }
}
