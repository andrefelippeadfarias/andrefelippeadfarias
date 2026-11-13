<?php

declare(strict_types=1);

namespace Ritmo\Application\Alerts;

use Closure;
use OutOfBoundsException;
use Ritmo\Domain\Alerts\AlertCode;
use Ritmo\Domain\Alerts\AlertDraft;
use Ritmo\Domain\Alerts\AlertLifecycleRules;
use Ritmo\Domain\Alerts\AlertRecord;
use Ritmo\Domain\Alerts\RaiseAction;
use Ritmo\Domain\Support\Clock;
use Ritmo\Infrastructure\Schedule\AlertLedger;

/**
 * Raises, refreshes, reopens and resolves alerts (operations spec 5.9):
 * - a draft whose key matches an open or acknowledged alert refreshes it (no new row, no e-mail);
 * - a resolved condition that comes back is a new row; a resolved event comes back only with
 *   newer evidence;
 * - after a run checked a scope, the condition alerts of that scope it did not raise are resolved
 *   (settle); a run that could not check resolves nothing;
 * - security and write alerts are acknowledged and resolved by an approver only.
 */
final class AlertService
{
    public function __construct(
        private readonly AlertLedger $ledger,
        private readonly AlertNotifier $notifier,
        private readonly Clock $clock,
    ) {
    }

    /**
     * @return AlertRecord|null the alert raised or refreshed; null when a person already dealt with it
     */
    public function raise(AlertDraft $draft, ?int $runId = null): ?AlertRecord
    {
        $latest = $this->ledger->latestByKey($draft->dedupeKey());
        $action = AlertLifecycleRules::decide($draft, $latest);
        $now = $this->clock->now();
        $alert = match ($action) {
            RaiseAction::Insert => $this->ledger->insert($draft, $runId, $now),
            RaiseAction::Refresh => $this->ledger->refresh($latest ?? throw new \LogicException('Nothing to refresh.'), $draft, $runId, $now),
            RaiseAction::Ignore => null,
        };
        if ($alert !== null) {
            $this->notifier->notify($alert, $action);
        }

        return $alert;
    }

    /**
     * @param list<AlertDraft> $drafts
     *
     * @return array<string, true> the dedupe keys raised (for settle())
     */
    public function raiseAll(array $drafts, ?int $runId = null): array
    {
        $keys = [];
        foreach ($drafts as $draft) {
            $this->raise($draft, $runId);
            $keys[$draft->dedupeKey()] = true;
        }

        return $keys;
    }

    /**
     * Resolves the condition alerts of a property, with these codes, that the run checked and did
     * not raise again.
     *
     * @param list<AlertCode>                 $codes      what the run checked
     * @param array<string, true>             $raisedKeys what it raised
     * @param Closure(AlertRecord): bool|null $inScope    limits the resolution to the rooms and nights the run checked
     *
     * @return int alerts resolved
     */
    public function settle(string $propertyId, array $codes, array $raisedKeys, ?Closure $inScope = null): int
    {
        $active = $this->ledger->active($propertyId, array_map(static fn (AlertCode $c): string => $c->value, $codes));
        $stale = AlertLifecycleRules::stale($active, $raisedKeys, $inScope);
        $now = $this->clock->now();
        foreach ($stale as $alert) {
            $this->ledger->resolve($alert->id, null, $now);
        }

        return count($stale);
    }

    /**
     * Resolves the open or acknowledged alert under a key, when there is one (an event that ends
     * by itself: the groups of a failed write completed, a freeze ended, a run started on time).
     */
    public function resolveKey(string $dedupeKey): bool
    {
        $latest = $this->ledger->latestByKey($dedupeKey);
        if ($latest === null || !$latest->status->isActive()) {
            return false;
        }
        $this->ledger->resolve($latest->id, null, $this->clock->now());

        return true;
    }

    /**
     * @param bool $isApprover the user has the approval capability (checked by the caller with the nonce)
     *
     * @throws OutOfBoundsException when the alert does not exist or the user may not act on it
     */
    public function acknowledge(int $alertId, int $userId, bool $isApprover): void
    {
        $this->allowed($alertId, $isApprover);
        $this->ledger->acknowledge($alertId, $userId, $this->clock->now());
    }

    /**
     * Resolving by hand is for approvers.
     *
     * @throws OutOfBoundsException when the alert does not exist or the user is not an approver
     */
    public function resolve(int $alertId, int $userId, bool $isApprover): void
    {
        if (!$isApprover) {
            throw new OutOfBoundsException('Only an approver resolves an alert.');
        }
        $this->allowed($alertId, true);
        $this->ledger->resolve($alertId, $userId, $this->clock->now());
    }

    private function allowed(int $alertId, bool $isApprover): void
    {
        $alert = $this->ledger->find($alertId) ?? throw new OutOfBoundsException(sprintf('Unknown alert %d.', $alertId));
        if (!$isApprover && (AlertCode::tryFrom($alert->code)?->needsApprover() ?? false)) {
            throw new OutOfBoundsException('Only an approver acknowledges a security or write alert.');
        }
    }
}
