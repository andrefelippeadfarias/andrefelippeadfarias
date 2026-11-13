<?php

declare(strict_types=1);

namespace Ritmo\Application\Verify;

use DateTimeImmutable;
use InvalidArgumentException;
use Ritmo\Domain\Plan\PlanItem;
use Ritmo\Domain\Pricing\Cadence\StepProposal;
use Ritmo\Domain\Write\Change\ChangeRecorder;
use Ritmo\Domain\Write\Ledger\ConfirmedSteps;
use Ritmo\Domain\Write\State\Provider;
use Ritmo\Domain\Write\ThirdParty\Observation;
use Ritmo\Infrastructure\Db\Repository\ChangeLogRepository;
use Ritmo\Infrastructure\Db\Repository\NightObservationRepository;
use Ritmo\Infrastructure\Db\Repository\WrittenStepRepository;

/**
 * Records what a write left, at the "stored" level only (decision D4): the change log (before,
 * after, why, who, how to undo), the step ledger rows of the pace steps of those nights (the
 * cadence reads them), and the read-back as the latest observation of each night (so the next
 * run does not take the plugin's own change for someone else's). A night not confirmed stored
 * leaves no trace in any of them.
 */
final class RecordService
{
    public function __construct(
        private readonly ChangeLogRepository $changes,
        private readonly WrittenStepRepository $steps,
        private readonly NightObservationRepository $observations,
    ) {
    }

    /**
     * @param list<NightConfirmation> $confirmations
     * @param list<StepProposal>      $proposals the pace steps of the plan's items
     * @param array<string, float>    $bases     room => listing base the percentages were written over
     * @param string                  $actor     ChangeRecord::ACTOR_AUTONOMOUS or "user:<id>"
     *
     * @throws InvalidArgumentException when the base of a written date rule's room is missing
     */
    public function record(string $propertyId, array $confirmations, array $proposals, array $bases, string $actor, ?int $runId, DateTimeImmutable $storedAt): RecordOutcome
    {
        $recorder = new ChangeRecorder($propertyId, $actor, $runId);
        $rows = [];
        $stored = [];
        $refs = [];
        $observed = [];
        foreach ($confirmations as $confirmation) {
            if (!$confirmation->isStored()) {
                continue;
            }
            $night = $confirmation->written;
            $base = $bases[$night->roomCode] ?? null;
            if ($base === null && $night->provider === Provider::Pricing) {
                throw new InvalidArgumentException(sprintf('The base of room "%s" is missing.', $night->roomCode));
            }
            $level = $confirmation->live?->status->isConfirmed() === true ? 'live' : 'stored';
            array_push($rows, ...$recorder->night($night, $confirmation->observed, $base ?? 1.0, $storedAt, $level));
            if ($night->provider === Provider::Pricing) {
                $key = PlanItem::keyOf($night->roomCode, $night->night);
                $stored[$key] = true;
                $refs[$key] = $night->journalRef;
            }
            $observed[] = new Observation($night->provider, $night->roomCode, $night->night, $confirmation->observed->of($night->provider), $storedAt, Observation::WRITTEN);
        }
        $changeIds = $this->changes->recordAll($rows);
        $stepIds = [];
        foreach (ConfirmedSteps::records($proposals, $stored, $storedAt) as $step) {
            $stepIds[] = $this->steps->record($propertyId, $step, $runId, $refs[PlanItem::keyOf($step->roomCode, $step->night)] ?? '');
        }
        $this->observations->recordChanged($propertyId, $observed, $runId);

        return new RecordOutcome($changeIds, $stepIds);
    }
}
