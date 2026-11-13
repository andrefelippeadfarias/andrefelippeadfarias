<?php

declare(strict_types=1);

namespace Ritmo\Domain\Alerts;

/**
 * Who hears about an alert, and when (operations spec 5.10):
 * - critical: by e-mail at once, once per episode (a new row); over the hourly cap it waits for
 *   the digest;
 * - warning: in the daily digest;
 * - information: never, except a plan awaiting approval (approvers get one e-mail per plan);
 * - a refresh of an open alert sends nothing, except the one escalation of a connector still down
 *   after the configured number of runs.
 */
final class NotificationPolicy
{
    public function __construct(
        private readonly int $maxPerHour,
        private readonly int $connectorEscalateRuns,
    ) {
    }

    public function delivery(AlertRecord $alert, RaiseAction $action, int $sentInLastHour): Delivery
    {
        $fresh = $action === RaiseAction::Insert || $this->escalates($alert, $action);
        if (!$fresh) {
            return Delivery::None;
        }
        $immediate = $alert->severity === AlertSeverity::Critical || $alert->code === AlertCode::ApprovalPending->value;
        if ($immediate) {
            return $sentInLastHour < $this->maxPerHour ? Delivery::Immediate : Delivery::Digest;
        }

        return $alert->severity === AlertSeverity::Warning ? Delivery::Digest : Delivery::None;
    }

    private function escalates(AlertRecord $alert, RaiseAction $action): bool
    {
        return $action === RaiseAction::Refresh
            && $alert->code === AlertCode::ConnectorDown->value
            && $alert->occurrences === $this->connectorEscalateRuns;
    }
}
