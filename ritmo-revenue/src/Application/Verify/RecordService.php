<?php

declare(strict_types=1);

namespace Ritmo\Application\Verify;

use DateTimeImmutable;
use InvalidArgumentException;
use Ritmo\Domain\Plan\PlanItem;
use Ritmo\Domain\Pricing\Cadence\StepProposal;
use Ritmo\Domain\Write\Change\ChangeRecorder;
use Ritmo\Domain\Write\Ledger\ConfirmedSteps;
use Ritmo\Domain\Write\State\ChangeField;
use Ritmo\Domain\Write\State\NightFields;
use Ritmo\Domain\Write\State\Provider;
use Ritmo\Domain\Write\State\WriteKind;
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
    /** The fields a read-back after a write covers, by system (JournalNights::fields()). */
    private const READ_BACK = [
        'pricing' => [ChangeField::FLOOR_PCT, ChangeField::CEILING_PCT, ChangeField::MIN_STAY, ChangeField::CHECK_IN_MASK, ChangeField::CHECK_OUT_MASK],
        'pms' => [ChangeField::PMS_MIN_STAY, ChangeField::PMS_MAX_STAY, ChangeField::PMS_RESTRICTION, ChangeField::PMS_AVAILABLE],
    ];

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
        $kinds = [];
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
            $kinds[] = $night->kind;
        }
        $observed = $this->merged($propertyId, $observed, $kinds);
        $changeIds = $this->changes->recordAll($rows);
        $stepIds = [];
        foreach (ConfirmedSteps::records($proposals, $stored, $storedAt) as $step) {
            $stepIds[] = $this->steps->record($propertyId, $step, $runId, $refs[PlanItem::keyOf($step->roomCode, $step->night)] ?? '');
        }
        $this->observations->recordChanged($propertyId, $observed, $runId);

        return new RecordOutcome($changeIds, $stepIds);
    }

    /**
     * The fields of the earlier observation a merged write replaced: those the read-back covers,
     * and a fixed floor or ceiling when the read-back holds a percentage in its place (one field
     * of the date rule holds either).
     *
     * @return list<string>
     */
    private static function replaced(Provider $provider, NightFields $readBack): array
    {
        $fields = self::READ_BACK[$provider->value];
        foreach ([ChangeField::FLOOR_PCT => ChangeField::FLOOR_FIXED, ChangeField::CEILING_PCT => ChangeField::CEILING_FIXED] as $percent => $fixed) {
            if ($provider === Provider::Pricing && $readBack->has($percent)) {
                $fields[] = $fixed;
            }
        }

        return $fields;
    }

    /**
     * The read-back of a night covers only the fields the plugin writes; a write merges them into
     * the rule that was there (the pricing system keeps the fields not sent, a fixed price say),
     * so the observation of a merged write keeps the other fields of the night's latest
     * observation. Without that the next run would take the night's own untouched fields for a
     * change by someone else. A deletion or a group's rewrite replaces the whole rule: kept as read.
     *
     * @param list<Observation> $observed
     * @param list<WriteKind>   $kinds    the kind of write of each observation
     *
     * @return list<Observation>
     */
    private function merged(string $propertyId, array $observed, array $kinds): array
    {
        if ($observed === []) {
            return [];
        }
        $nights = array_map(static fn (Observation $o): DateTimeImmutable => $o->night, $observed);
        $latest = $this->observations->latest($propertyId, min($nights), max($nights));
        $out = [];
        foreach ($observed as $i => $o) {
            $last = $latest[$o->key()] ?? null;
            $out[] = $kinds[$i] !== WriteKind::Write || $last === null
                ? $o
                : new Observation($o->provider, $o->roomCode, $o->night, $last->fields->without(self::replaced($o->provider, $o->fields))->merge($o->fields), $o->observedAt, Observation::WRITTEN);
        }

        return $out;
    }
}
