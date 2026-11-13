<?php

declare(strict_types=1);

namespace Ritmo\Application\Run;

use Ritmo\Application\Alerts\AlertService;
use Ritmo\Application\ConnectorSource;
use Ritmo\Application\ShadowRun\RunContext;
use Ritmo\Application\Verify\RecordService;
use Ritmo\Application\Write\Journal\StateReader;
use Ritmo\Application\Write\Resume\BrokenGroup;
use Ritmo\Application\Write\Resume\ResumeReport;
use Ritmo\Application\Write\Resume\ResumeService;
use Ritmo\Domain\Alerts\OperationAlerts;
use Ritmo\Domain\Pricing\Cadence\StepProposal;
use Ritmo\Domain\Support\Clock;
use Ritmo\Domain\Support\Days;
use Ritmo\Domain\Write\State\Provider;
use Ritmo\Domain\Write\State\WrittenNight;

/**
 * The write journal seen by the watch stage of every run, whatever its mode, before anything is
 * decided: the rows earlier writes left with an unknown outcome are read back and classified
 * (ResumeService), what a request that died while writing left applied is recorded as its own
 * verify stage would have, and a deletion-and-rewrite group left open raises its alert again.
 * The nights of the plugin's own writes that no run observed yet are handed to the third-party
 * comparison, which leaves them alone; a night that diverged is someone else's (review findings of
 * the M3 correction phase: the read-back ran only in a run that wrote, after the comparison).
 */
final class JournalWatch
{
    public function __construct(
        private readonly ConnectorSource $connectors,
        private readonly ResumeService $resume,
        private readonly RecordService $record,
        private readonly AlertService $alerts,
        private readonly Clock $clock,
    ) {
    }

    /**
     * @throws \Ritmo\Contracts\Exception\ConnectorException when the systems cannot be read back: the run fails
     */
    public function settle(RunContext $context): ResumeReport
    {
        $settled = $this->resume->resume($context->runId, $context->property->id, new StateReader($this->connectors->pricing(), $this->connectors->pms()));
        $this->groupsOpen($context, $settled->broken);
        $this->recordRecovered($context, $settled);

        return $settled;
    }

    /**
     * Nights holding a write of the plugin that no run observed yet (a group left open, a write
     * whose unknown outcome the re-read found applied): not a change by someone else.
     *
     * @return array<string, bool>
     */
    public static function ownNights(ResumeReport $settled): array
    {
        $keys = [];
        foreach ($settled->ownUnobserved() as $row) {
            $payload = is_array($row['payload'] ?? null) ? $row['payload'] : [];
            $room = (string) ($payload['room'] ?? '');
            if ($room === '') {
                continue;
            }
            $provider = $row['provider'] === 'pms' ? Provider::Pms : Provider::Pricing;
            foreach ((array) ($row['dates'] ?? []) as $night) {
                if (is_string($night) && Days::isValid($night)) {
                    $keys[WrittenNight::keyOf($provider, $room, Days::date($night))] = true;
                }
            }
        }

        return $keys;
    }

    /**
     * Records what a request that died while writing left applied (change log, step ledger and
     * observations), as its own verify stage would have: so the next decision knows the pace
     * steps already given (never a second step for the same night) and an undo can bring them back.
     */
    private function recordRecovered(RunContext $context, ResumeReport $settled): void
    {
        $byRun = [];
        foreach ($settled->recovered() as $row) {
            $byRun[(int) $row['run_id']][] = $row;
        }
        $now = $this->clock->now();
        $live = [];
        foreach ($context->rooms as $room) {
            $live[$room->code] = $room->base;
        }
        foreach ($byRun as $runId => $rows) {
            $actor = $rows[0]['payload']['actor'] ?? null;
            $confirmed = JournalNights::confirmed($rows, $now);
            if (!is_string($actor) || $confirmed === []) {
                continue;
            }
            $bases = $proposals = [];
            foreach ($rows as $row) {
                $payload = $row['payload'];
                if (is_numeric($payload['base'] ?? null)) {
                    $bases[(string) $payload['room']] = (float) $payload['base'];
                }
                foreach ((array) ($payload['steps'] ?? []) as $step) {
                    if (is_array($step)) {
                        $proposals[] = StepProposal::fromArray($step);
                    }
                }
            }
            $this->record->record($context->property->id, $confirmed, $proposals, $bases + $live, $actor, $runId, $now);
        }
    }

    /**
     * Raised again by every run while a group stays open: its nights have no date rule.
     *
     * @param list<BrokenGroup> $broken
     */
    private function groupsOpen(RunContext $context, array $broken): void
    {
        if ($broken === []) {
            return;
        }
        $nights = [];
        foreach ($broken as $group) {
            foreach ($group->rewrite as $entry) {
                $nights[] = $group->roomCode . '|' . ($entry['date'] ?? '');
            }
        }
        $this->alerts->raise(OperationAlerts::groupsOpen($context->property->id, count($broken), $nights, $context->mode->value, $this->clock->now()), $context->runId);
    }
}
