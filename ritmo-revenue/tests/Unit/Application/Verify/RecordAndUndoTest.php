<?php

declare(strict_types=1);

namespace Ritmo\Tests\Unit\Application\Verify;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Ritmo\Application\Approval\ApprovalKind;
use Ritmo\Application\Approval\ApprovalRefused;
use Ritmo\Application\Approval\ApprovalService;
use Ritmo\Application\Approval\CapabilityCheck;
use Ritmo\Application\Verify\NightConfirmation;
use Ritmo\Application\Verify\RecordOutcome;
use Ritmo\Application\Verify\RecordService;
use Ritmo\Application\Verify\StoredStatus;
use Ritmo\Application\Verify\UndoService;
use Ritmo\Domain\Model\DateOverride;
use Ritmo\Domain\Model\ListingInfo;
use Ritmo\Domain\Model\RoomType;
use Ritmo\Domain\Model\SyncStatus;
use Ritmo\Domain\Plan\Validator;
use Ritmo\Domain\Pricing\Cadence\SoldBase;
use Ritmo\Domain\Pricing\Cadence\StepProposal;
use Ritmo\Domain\Pricing\StepDirection;
use Ritmo\Domain\Support\Days;
use Ritmo\Domain\Write\Change\ChangeRecord;
use Ritmo\Domain\Write\Change\UndoSkip;
use Ritmo\Domain\Write\State\ChangeField;
use Ritmo\Domain\Write\State\NightFields;
use Ritmo\Domain\Write\State\Provider;
use Ritmo\Domain\Write\State\WriteKind;
use Ritmo\Domain\Write\State\WrittenNight;
use Ritmo\Infrastructure\Db\PdoDatabase;
use Ritmo\Infrastructure\Db\Repository\ChangeLogRepository;
use Ritmo\Infrastructure\Db\Repository\NightObservationRepository;
use Ritmo\Infrastructure\Db\Repository\PlanApprovalRepository;
use Ritmo\Infrastructure\Db\Repository\WrittenStepRepository;
use Ritmo\Tests\Fakes\InMemoryPmsConnector;
use Ritmo\Tests\Fakes\InMemoryPricingConnector;
use Ritmo\Tests\Fakes\MovableClock;
use Ritmo\Tests\Unit\Domain\EngineSample;
use Ritmo\Tests\Unit\Domain\Plan\PlanSample;

/**
 * Only what is confirmed stored is recorded (change log, step ledger, observation); an undo is a
 * plan validated and approved by the person who asked, and once written it marks the rows and
 * their steps undone (docs/CHANGELOG-UNDO.md).
 */
final class RecordAndUndoTest extends TestCase
{
    private PdoDatabase $db;

    private ChangeLogRepository $changes;

    private WrittenStepRepository $steps;

    private NightObservationRepository $observations;

    protected function setUp(): void
    {
        $this->db = VerifyDatabase::create();
        $this->changes = new ChangeLogRepository($this->db);
        $this->steps = new WrittenStepRepository($this->db);
        $this->observations = new NightObservationRepository($this->db);
    }

    public function testOnlyStoredNightsAreRecorded(): void
    {
        $outcome = $this->record();

        self::assertCount(1, $outcome->changeIds);
        self::assertCount(1, $outcome->stepIds, 'the step of the mismatched night is not in the ledger');
        $row = $this->changes->byIds($outcome->changeIds)[0];
        self::assertSame([ChangeField::FLOOR_PCT, -10, -20, 'pace_step', 'stored', 21], [$row->field, $row->oldValue, $row->newValue, $row->reasonCode, $row->confirmLevel, $row->runId]);
        $ledger = $this->steps->ledger('inn', Days::date('2027-03-01'), Days::date('2027-03-31'));
        self::assertCount(1, $ledger->records());
        self::assertSame('2027-03-10', Days::key($ledger->records()[0]->night));
        $observed = $this->observations->latest('inn', Days::date('2027-03-10'), Days::date('2027-03-11'));
        self::assertSame(['pricing|Deluxe|2027-03-10'], array_keys($observed), 'the read-back is the new observation');
    }

    public function testAnUndoIsValidatedAndApprovedByThePersonWhoAsked(): void
    {
        $outcome = $this->record();
        [$service, $approvals] = $this->undo([new DateOverride(Days::date('2027-03-10'), -20.0)]);

        $request = $service->request(EngineSample::property(), self::rooms(), $outcome->changeIds, 3, new Validator(EngineSample::rules(), PlanSample::clock()), PlanSample::context());

        self::assertSame([], $request->refused);
        self::assertCount(1, $request->grants);
        self::assertSame(ApprovalKind::Undo, $request->grants[0]->kind);
        self::assertSame(['item|Deluxe|2027-03-10'], $request->grants[0]->approvedKeys());
        self::assertSame(3, $approvals->grant($request->grants[0]->approvalId)->approvedBy);
    }

    public function testAnUndoOverANightChangedSinceIsLeftOut(): void
    {
        $outcome = $this->record();
        [$service] = $this->undo([new DateOverride(Days::date('2027-03-10'), -25.0)]);

        $request = $service->request(EngineSample::property(), self::rooms(), $outcome->changeIds, 3, new Validator(EngineSample::rules(), PlanSample::clock()), PlanSample::context());

        self::assertSame([], $request->grants);
        self::assertSame(UndoSkip::CONFLICT, $request->draft->skipped[0]->reason);
    }

    public function testOnlyAnApproverMayAskForAnUndo(): void
    {
        $outcome = $this->record();
        [$service] = $this->undo([]);

        $this->expectException(ApprovalRefused::class);
        $service->request(EngineSample::property(), self::rooms(), $outcome->changeIds, 9, new Validator(EngineSample::rules(), PlanSample::clock()), PlanSample::context());
    }

    public function testAConfirmedUndoMarksTheRowsAndTheirSteps(): void
    {
        $outcome = $this->record();
        [$service] = $this->undo([]);

        self::assertSame(1, $service->confirm($outcome->changeIds, new DateTimeImmutable('2027-03-03T14:00:00Z')));
        self::assertTrue($this->changes->byIds($outcome->changeIds)[0]->isUndone());
        self::assertSame([], $this->steps->ledger('inn', Days::date('2027-03-01'), Days::date('2027-03-31'))->records(), 'the cadence no longer counts it');
        self::assertSame(0, $service->confirm($outcome->changeIds, new DateTimeImmutable('2027-03-03T15:00:00Z')), 'once');
    }

    private function record(): RecordOutcome
    {
        $at = new DateTimeImmutable('2027-03-03T12:00:00Z');
        $written = static fn (string $night): WrittenNight => new WrittenNight(Provider::Pricing, 'Deluxe', Days::date($night), WriteKind::Write, new NightFields([ChangeField::FLOOR_PCT => -20]), new NightFields([ChangeField::FLOOR_PCT => -10]), $at, '', 'pace_step', 'Pace step', null, 'op-1');
        $confirmations = [
            new NightConfirmation($written('2027-03-10'), StoredStatus::Stored, new NightFields([ChangeField::FLOOR_PCT => -20])),
            new NightConfirmation($written('2027-03-11'), StoredStatus::Mismatch, new NightFields([ChangeField::FLOOR_PCT => -10]), [ChangeField::FLOOR_PCT]),
        ];
        $proposal = static fn (string $night): StepProposal => new StepProposal('Deluxe', Days::date($night), 'behind', StepDirection::Down, 900.0, 800.0, 800, 1, SoldBase::Room, 4);

        return (new RecordService($this->changes, $this->steps, $this->observations))->record('inn', $confirmations, [$proposal('2027-03-10'), $proposal('2027-03-11')], ['Deluxe' => 1000.0], ChangeRecord::ACTOR_AUTONOMOUS, 21, $at->modify('+20 seconds'));
    }

    /**
     * @param list<DateOverride> $overrides what the pricing system holds now
     *
     * @return array{0: UndoService, 1: ApprovalService}
     */
    private function undo(array $overrides): array
    {
        $clock = new MovableClock(PlanSample::NOW);
        $capabilities = new Capabilities([3 => [CapabilityCheck::APPROVE]]);
        $approvals = new ApprovalService(new PlanApprovalRepository($this->db), $capabilities, $clock);
        $pricing = new InMemoryPricingConnector([new ListingInfo('pricing-1', 'Deluxe', 600.0, 1000.0, 3000.0, 'EUR', new SyncStatus(true))], [], ['pricing-1' => $overrides]);

        return [new UndoService($this->changes, $this->steps, $pricing, new InMemoryPmsConnector(), $approvals, $capabilities, $clock), $approvals];
    }

    /**
     * @return array<string, RoomType>
     */
    private static function rooms(): array
    {
        return ['Deluxe' => EngineSample::deluxe(), 'Standard' => EngineSample::standard()];
    }
}
