<?php

declare(strict_types=1);

namespace Ritmo\Application\Run;

use Closure;
use Ritmo\Application\Alerts\AlertNotifier;
use Ritmo\Application\Alerts\AlertService;
use Ritmo\Application\Approval\ApprovalDesk;
use Ritmo\Application\Approval\ApprovalService;
use Ritmo\Application\Approval\CapabilityCheck;
use Ritmo\Application\ConnectorSource;
use Ritmo\Application\EmergencySwitch;
use Ritmo\Application\Market\MarketRulerAssembler;
use Ritmo\Application\Market\MarketSeriesService;
use Ritmo\Application\Mode\EmergencyControl;
use Ritmo\Application\Mode\ModePreconditions;
use Ritmo\Application\Mode\ModeService;
use Ritmo\Application\Schedule\LightRoundService;
use Ritmo\Application\Schedule\LockedRunLauncher;
use Ritmo\Application\Schedule\MovementTracker;
use Ritmo\Application\Schedule\PauseService;
use Ritmo\Application\Schedule\RunNowService;
use Ritmo\Application\Schedule\ScheduleHousekeeping;
use Ritmo\Application\Schedule\SchedulerTick;
use Ritmo\Application\Schedule\StallMonitor;
use Ritmo\Application\ShadowRun\CollectStage;
use Ritmo\Application\ShadowRun\DecideStage;
use Ritmo\Application\ShadowRun\MeasureStage;
use Ritmo\Application\ShadowRun\RunInputsReader;
use Ritmo\Application\ShadowRun\RunPipeline;
use Ritmo\Application\ShadowRun\ShadowRunService;
use Ritmo\Application\ShadowRun\ShadowStage;
use Ritmo\Application\ShadowRun\ValidateStage;
use Ritmo\Application\Verify\RecordService;
use Ritmo\Application\Verify\ThirdPartyWatch;
use Ritmo\Application\Verify\UndoService;
use Ritmo\Application\Write\Execute\WriteExecutor;
use Ritmo\Application\Write\Gate\WriteGate;
use Ritmo\Application\Write\Journal\WriteJournal;
use Ritmo\Application\Write\Quota\RecalcGuard;
use Ritmo\Application\Write\Resume\ResumeService;
use Ritmo\Connectors\PriceLabs\PriceLabsConnector;
use Ritmo\Contracts\KeyValueStore;
use Ritmo\Contracts\Logger;
use Ritmo\Domain\Alerts\NotificationPolicy;
use Ritmo\Domain\Config\RuleSet;
use Ritmo\Domain\Support\Clock;
use Ritmo\Infrastructure\Db\Database;
use Ritmo\Infrastructure\Db\Repository\AlertRepository;
use Ritmo\Infrastructure\Db\Repository\BookingRepository;
use Ritmo\Infrastructure\Db\Repository\ChangeLogRepository;
use Ritmo\Infrastructure\Db\Repository\CompetitorRepository;
use Ritmo\Infrastructure\Db\Repository\DetectedFreezeRepository;
use Ritmo\Infrastructure\Db\Repository\InventorySnapshotRepository;
use Ritmo\Infrastructure\Db\Repository\LadderReferenceRepository;
use Ritmo\Infrastructure\Db\Repository\MarketAnchorRepository;
use Ritmo\Infrastructure\Db\Repository\MarketSeriesRepository;
use Ritmo\Infrastructure\Db\Repository\NightObservationRepository;
use Ritmo\Infrastructure\Db\Repository\OperationEventRepository;
use Ritmo\Infrastructure\Db\Repository\OrderRepository;
use Ritmo\Infrastructure\Db\Repository\PlanApprovalRepository;
use Ritmo\Infrastructure\Db\Repository\PlanItemRepository;
use Ritmo\Infrastructure\Db\Repository\PropertyRepository;
use Ritmo\Infrastructure\Db\Repository\RecalcAttemptRepository;
use Ritmo\Infrastructure\Db\Repository\RoomTypeRepository;
use Ritmo\Infrastructure\Db\Repository\RuleSetRepository;
use Ritmo\Infrastructure\Db\Repository\RunPayloadRepository;
use Ritmo\Infrastructure\Db\Repository\RunRepository;
use Ritmo\Infrastructure\Db\Repository\StaySampleRepository;
use Ritmo\Infrastructure\Db\Repository\StepLedgerRepository;
use Ritmo\Infrastructure\Db\Repository\WaveHoldRepository;
use Ritmo\Infrastructure\Db\Repository\WriteOpRepository;
use Ritmo\Infrastructure\Db\Repository\WrittenStepRepository;
use Ritmo\Infrastructure\Lock\DbLock;
use Ritmo\Infrastructure\Schedule\AlertLedger;
use Ritmo\Infrastructure\Schedule\BookingMovementRepository;
use Ritmo\Infrastructure\Schedule\Mailer;
use Ritmo\Infrastructure\Schedule\OperationStateRepository;
use Ritmo\Infrastructure\Schedule\RecipientDirectory;
use Ritmo\Infrastructure\Schedule\ScheduledRunRepository;
use Ritmo\Infrastructure\Support\Sleeper;

/**
 * The run machinery wired once: the pipeline of every run (collect, watch, measure, decide,
 * validate, shadow, write, verify, report), the services around it (shadow run, run now, the
 * scheduler tick, approvals, modes, undo) and their repositories. The WordPress composition root
 * builds it over wpdb; the end-to-end tests build it over SQLite and the simulator. Objects are
 * made on first use and shared.
 *
 * The rules of the configuration-dependent pieces (write gate limits, quota, notifications) are
 * the rule set in force when the stack is built; a run reads its own rules version when it starts.
 */
final class RunStack
{
    /** E-mails per hour the alerts may send (operations spec O29). */
    public const MAX_EMAILS_PER_HOUR = 6;

    /** @var array<string, object> */
    private array $made = [];

    /**
     * @param (Closure(): void)|null         $wake     asks the scheduler to tick now (after an approval)
     * @param (Closure(): (int|null))|null   $timeLeft seconds the request may still work, for the write's deadline
     */
    public function __construct(
        public readonly Database $db,
        public readonly Clock $clock,
        public readonly KeyValueStore $store,
        public readonly Logger $logger,
        public readonly ConnectorSource $connectors,
        public readonly EmergencySwitch $switch,
        public readonly WriteGate $gate,
        public readonly CapabilityCheck $capabilities,
        private readonly Mailer $mailer,
        private readonly RecipientDirectory $recipients,
        private readonly Sleeper $sleeper,
        private readonly ?Closure $wake = null,
        private readonly ?Closure $timeLeft = null,
    ) {
    }

    public function rules(): RuleSet
    {
        return $this->ruleSets()->current() ?? RuleSet::defaults();
    }

    public function properties(): PropertyRepository
    {
        return $this->once(__FUNCTION__, fn (): PropertyRepository => new PropertyRepository($this->db, $this->clock));
    }

    public function roomTypes(): RoomTypeRepository
    {
        return $this->once(__FUNCTION__, fn (): RoomTypeRepository => new RoomTypeRepository($this->db, $this->clock));
    }

    public function ruleSets(): RuleSetRepository
    {
        return $this->once(__FUNCTION__, fn (): RuleSetRepository => new RuleSetRepository($this->db, $this->clock));
    }

    public function runs(): RunRepository
    {
        return $this->once(__FUNCTION__, fn (): RunRepository => new RunRepository($this->db));
    }

    public function planItems(): PlanItemRepository
    {
        return $this->once(__FUNCTION__, fn (): PlanItemRepository => new PlanItemRepository($this->db, $this->clock));
    }

    public function snapshots(): InventorySnapshotRepository
    {
        return $this->once(__FUNCTION__, fn (): InventorySnapshotRepository => new InventorySnapshotRepository($this->db));
    }

    public function bookings(): BookingRepository
    {
        return $this->once(__FUNCTION__, fn (): BookingRepository => new BookingRepository($this->db));
    }

    public function writeOps(): WriteOpRepository
    {
        return $this->once(__FUNCTION__, fn (): WriteOpRepository => new WriteOpRepository($this->db));
    }

    public function changeLog(): ChangeLogRepository
    {
        return $this->once(__FUNCTION__, fn (): ChangeLogRepository => new ChangeLogRepository($this->db));
    }

    public function writtenSteps(): WrittenStepRepository
    {
        return $this->once(__FUNCTION__, fn (): WrittenStepRepository => new WrittenStepRepository($this->db));
    }

    public function approvalRequests(): PlanApprovalRepository
    {
        return $this->once(__FUNCTION__, fn (): PlanApprovalRepository => new PlanApprovalRepository($this->db));
    }

    public function events(): OperationEventRepository
    {
        return $this->once(__FUNCTION__, fn (): OperationEventRepository => new OperationEventRepository($this->db));
    }

    public function pauses(): PauseService
    {
        return $this->once(__FUNCTION__, fn (): PauseService => new PauseService($this->properties(), $this->states(), $this->clock, $this->logger, $this->events()));
    }

    public function preconditions(): ModePreconditions
    {
        return $this->once(__FUNCTION__, fn (): ModePreconditions => new ModePreconditions($this->runs(), new AlertLedger($this->db), $this->holds(), $this->switch, $this->store, $this->clock));
    }

    public function holds(): WaveHoldRepository
    {
        return $this->once(__FUNCTION__, fn (): WaveHoldRepository => new WaveHoldRepository($this->db));
    }

    public function freezes(): DetectedFreezeRepository
    {
        return $this->once(__FUNCTION__, fn (): DetectedFreezeRepository => new DetectedFreezeRepository($this->db));
    }

    public function observations(): NightObservationRepository
    {
        return $this->once(__FUNCTION__, fn (): NightObservationRepository => new NightObservationRepository($this->db));
    }

    public function slots(): ScheduledRunRepository
    {
        return $this->once(__FUNCTION__, fn (): ScheduledRunRepository => new ScheduledRunRepository($this->db, $this->clock));
    }

    public function states(): OperationStateRepository
    {
        return $this->once(__FUNCTION__, fn (): OperationStateRepository => new OperationStateRepository($this->db, $this->clock));
    }

    public function locks(): DbLock
    {
        return $this->once(__FUNCTION__, fn (): DbLock => new DbLock($this->db, $this->clock));
    }

    public function alerts(): AlertService
    {
        return $this->once(__FUNCTION__, function (): AlertService {
            $ledger = new AlertLedger($this->db);
            $policy = new NotificationPolicy(self::MAX_EMAILS_PER_HOUR, $this->rules()->alerts()->connectorDownEscalateRuns);

            return new AlertService($ledger, new AlertNotifier($ledger, $this->mailer, $this->recipients, $policy, $this->clock, $this->logger), $this->clock);
        });
    }

    public function recalcGuard(): RecalcGuard
    {
        return $this->once(__FUNCTION__, fn (): RecalcGuard => new RecalcGuard(new RecalcAttemptRepository($this->db), $this->clock, PriceLabsConnector::ID, $this->rules()->quota(), $this->rules()->write()->callTimeoutSeconds));
    }

    public function quotaRunStart(): QuotaRunStart
    {
        return $this->once(__FUNCTION__, fn (): QuotaRunStart => new QuotaRunStart($this->slots(), $this->runs(), $this->recalcGuard(), $this->clock));
    }

    public function inputs(): RunInputsReader
    {
        return $this->once(__FUNCTION__, fn (): RunInputsReader => new RunInputsReader(
            $this->snapshots(),
            new MarketAnchorRepository($this->db, $this->clock),
            new StepLedgerRepository($this->db),
            new OrderRepository($this->db, $this->clock),
            new MarketRulerAssembler(new MarketSeriesRepository($this->db), new MarketAnchorRepository($this->db, $this->clock), new LadderReferenceRepository($this->db)),
            $this->marketSeries(),
            new ThirdPartyWatch($this->observations(), $this->freezes(), $this->capabilities, $this->clock, $this->rules()->thirdParty()),
            new WeeklyReference($this->store),
        ));
    }

    public function marketSeries(): MarketSeriesService
    {
        return $this->once(__FUNCTION__, fn (): MarketSeriesService => new MarketSeriesService(
            $this->db,
            $this->clock,
            $this->properties(),
            $this->ruleSets(),
            new CompetitorRepository($this->db, $this->clock),
            new StaySampleRepository($this->db),
            new MarketSeriesRepository($this->db),
            new MarketAnchorRepository($this->db, $this->clock),
        ));
    }

    public function pipeline(): RunPipeline
    {
        return $this->once(__FUNCTION__, fn (): RunPipeline => new RunPipeline([
            new CollectStage($this->connectors, $this->snapshots(), $this->bookings(), $this->clock),
            $this->watchStage(),
            new MeasureStage($this->snapshots(), $this->bookings(), new AlertRepository($this->db, $this->clock), $this->clock),
            new DecideStage($this->inputs(), $this->clock, $this->connectors->pricingPmsName()),
            new ValidateStage(),
            new ShadowStage($this->planItems(), new RunPayloadRepository($this->db, $this->clock)),
            $this->writeStage(),
            new VerifyStage($this->writeOps(), $this->record(), $this->writtenSteps(), $this->connectors, $this->clock),
            new ReportStage(),
        ], $this->runs(), $this->db, $this->switch, $this->clock, $this->logger));
    }

    public function opener(): RunOpener
    {
        return $this->once(__FUNCTION__, fn (): RunOpener => new RunOpener($this->roomTypes(), $this->ruleSets(), $this->runs(), $this->clock, $this->logger));
    }

    public function launcher(): LockedRunLauncher
    {
        return $this->once(__FUNCTION__, fn (): LockedRunLauncher => new LockedRunLauncher(
            $this->runs(),
            $this->locks(),
            new PipelineRunExecutor($this->opener(), $this->runs(), $this->pipeline()),
            $this->clock,
            $this->logger,
        ));
    }

    public function runNow(): RunNowService
    {
        return $this->once(__FUNCTION__, fn (): RunNowService => new RunNowService($this->properties(), $this->launcher(), $this->switch));
    }

    public function shadowRuns(): ShadowRunService
    {
        return $this->once(__FUNCTION__, fn (): ShadowRunService => new ShadowRunService($this->properties(), $this->opener(), $this->runs(), $this->locks(), $this->switch, $this->pipeline(), $this->clock, $this->logger));
    }

    public function tick(): SchedulerTick
    {
        return $this->once(__FUNCTION__, fn (): SchedulerTick => new SchedulerTick(
            $this->properties(),
            $this->slots(),
            $this->states(),
            $this->runs(),
            $this->locks(),
            $this->store,
            $this->switch,
            $this->launcher(),
            new CurrentSchedule($this->ruleSets()),
            $this->alerts(),
            new ScheduleHousekeeping($this->slots(), new BookingMovementRepository($this->db), $this->store, $this->clock),
            $this->clock,
            $this->logger,
        ));
    }

    public function stallMonitor(): StallMonitor
    {
        return $this->once(__FUNCTION__, fn (): StallMonitor => new StallMonitor($this->store, $this->slots(), $this->alerts(), $this->clock));
    }

    public function approvalDesk(): ApprovalDesk
    {
        return $this->once(__FUNCTION__, fn (): ApprovalDesk => new ApprovalDesk($this->approvalRequests(), $this->capabilities, $this->slots(), $this->events(), $this->properties(), $this->clock, $this->wake));
    }

    public function modes(): ModeService
    {
        return $this->once(__FUNCTION__, fn (): ModeService => new ModeService(
            $this->properties(),
            $this->events(),
            new ApprovalService($this->approvalRequests(), $this->capabilities, $this->clock, $this->rules()->approval()),
            $this->capabilities,
            $this->alerts(),
            $this->store,
            $this->clock,
            $this->preconditions(),
        ));
    }

    public function emergency(): EmergencyControl
    {
        return $this->once(__FUNCTION__, fn (): EmergencyControl => new EmergencyControl(
            $this->switch,
            $this->properties(),
            new ApprovalService($this->approvalRequests(), $this->capabilities, $this->clock, $this->rules()->approval()),
            $this->events(),
            $this->clock,
        ));
    }

    public function undo(): UndoService
    {
        return $this->once(__FUNCTION__, fn (): UndoService => new UndoService(
            $this->changeLog(),
            $this->writtenSteps(),
            $this->connectors->pricing(),
            $this->connectors->pms(),
            new ApprovalService($this->approvalRequests(), $this->capabilities, $this->clock, $this->rules()->approval()),
            $this->capabilities,
            $this->clock,
        ));
    }

    private function watchStage(): WatchStage
    {
        $alerts = $this->alerts();

        return new WatchStage(
            $this->connectors,
            new MovementTracker($this->states(), new BookingMovementRepository($this->db), $alerts, $this->sleeper, $this->clock),
            $alerts,
            $this->holds(),
            $this->observations(),
            $this->freezes(),
            $this->capabilities,
            new LightScope(new LightRoundService($this->snapshots(), $alerts), $this->recalcGuard(), $this->runs(), $this->quotaRunStart()),
            $this->snapshots(),
            $this->bookings(),
            new JournalWatch($this->connectors, new ResumeService($this->writeOps()), $this->record(), $alerts, $this->clock),
            new ListingWatch($this->roomTypes(), $this->writeOps(), $this->events(), $alerts, $this->clock),
            $this->clock,
        );
    }

    public function executor(): WriteExecutor
    {
        return $this->once(__FUNCTION__, fn (): WriteExecutor => new WriteExecutor($this->gate, $this->switch, $this->clock, new WriteJournal($this->writeOps(), $this->clock), $this->rules()->write(), $this->recalcGuard()));
    }

    public function undoWriter(): UndoWriter
    {
        return $this->once(__FUNCTION__, fn (): UndoWriter => new UndoWriter(
            $this->properties(),
            $this->roomTypes(),
            $this->runs(),
            new OrderRepository($this->db, $this->clock),
            $this->undo(),
            $this->record(),
            $this->writeOps(),
            $this->events(),
            $this->connectors,
            $this->gate,
            $this->executor(),
            $this->switch,
            $this->locks(),
            $this->capabilities,
            $this->clock,
        ));
    }

    private function record(): RecordService
    {
        return $this->once(__FUNCTION__, fn (): RecordService => new RecordService($this->changeLog(), $this->writtenSteps(), $this->observations()));
    }

    private function writeStage(): WriteStage
    {
        return new WriteStage(
            new PlanRebuild($this->inputs(), $this->clock, $this->connectors->pricingPmsName()),
            new WriteRunner($this->connectors, $this->gate, $this->executor(), $this->writeOps(), $this->quotaRunStart(), $this->capabilities, $this->clock, $this->timeLeft),
            $this->approvalRequests(),
            $this->holds(),
            $this->capabilities,
            $this->modes(),
            $this->properties(),
            $this->alerts(),
            $this->events(),
            new WriteReport($this->alerts(), $this->clock),
            $this->clock,
        );
    }

    /**
     * @template T of object
     *
     * @param Closure(): T $make
     *
     * @return T
     */
    private function once(string $key, Closure $make): object
    {
        return $this->made[$key] ??= $make();
    }
}
