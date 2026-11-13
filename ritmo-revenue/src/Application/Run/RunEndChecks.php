<?php

declare(strict_types=1);

namespace Ritmo\Application\Run;

use Ritmo\Application\Alerts\AlertService;
use Ritmo\Application\Market\MarketReminder;
use Ritmo\Application\Market\MarketSettings;
use Ritmo\Application\Report\Store\SnapshotStore;
use Ritmo\Application\ShadowRun\RunContext;
use Ritmo\Domain\Alerts\AlertCode;
use Ritmo\Domain\Alerts\AlertDraft;
use Ritmo\Domain\Alerts\RunAlerts;
use Ritmo\Domain\Config\InvalidConfig;
use Ritmo\Domain\Report\Config\ReportsConfig;
use Ritmo\Domain\Report\Config\ReportsFields;
use Ritmo\Domain\Report\Review\ReviewDue;
use Ritmo\Infrastructure\Schedule\AlertLedger;

/**
 * The checks at the end of a full run (market spec 1.7, reports spec 2.4, M3 pendency D5): old
 * market prices, nights an active sales ladder cannot price, steps not confirmed at the channel
 * (not while the pricing system's own push is known to be off or late, which has its alert), and
 * the 12-month review due. Each condition is raised once per property and resolved by the run
 * that no longer finds it. Reads stored data only; writes nothing outside the plugin.
 */
final class RunEndChecks
{
    public function __construct(
        private readonly MarketReminder $market,
        private readonly SnapshotStore $snapshots,
        private readonly AlertService $alerts,
        private readonly AlertLedger $ledger,
    ) {
    }

    /**
     * @param array<string, mixed> $verify the summary of the verify stage
     *
     * @return int|null the age in days of the newest market price (null: none)
     */
    public function check(RunContext $context, array $verify): ?int
    {
        $property = $context->property;
        $drafts = [];
        $age = null;
        try {
            $market = $this->market->check($property, $context->rules, MarketSettings::of($context->rules), $context->today);
            $age = $market['age_days'];
            if ($market['stale']) {
                $drafts[] = RunAlerts::marketStale($property->id, $market['age_days'], $market['newest']);
            }
            if ($market['gaps'] !== []) {
                $drafts[] = RunAlerts::marketGap($property->id, array_column($market['gaps'], 'night'));
            }
        } catch (InvalidConfig) {
            // A market section that does not read: the rules screen shows it; no market alert.
        }
        $overdue = (int) ($verify['delivery']['overdue'] ?? 0);
        if ($overdue > 0 && $this->ledger->active($property->id, [AlertCode::PushDisabled->value, AlertCode::PushStale->value]) === []) {
            $drafts[] = RunAlerts::deliveryOverdue($property->id, $overdue);
        }
        $review = $this->reviewDue($context);
        if ($review !== null) {
            $drafts[] = $review;
        }
        $raised = $this->alerts->raiseAll($drafts, $context->runId);
        $this->alerts->settle($property->id, RunAlerts::CODES, $raised);

        return $age;
    }

    private function reviewDue(RunContext $context): ?AlertDraft
    {
        try {
            $config = ReportsConfig::fromArray((array) ($context->rules->toArray()[ReportsFields::ROOT] ?? []));
        } catch (InvalidConfig) {
            return null;
        }
        $last = $this->snapshots->lastTaken($context->property->id);

        return ReviewDue::of($context->today, $last, $config->review->intervalDays)->due ? RunAlerts::reviewDue($context->property->id, $last) : null;
    }
}
