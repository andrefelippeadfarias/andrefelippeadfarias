<?php

declare(strict_types=1);

namespace Ritmo\Application\Run;

use Closure;
use DateTimeImmutable;
use OutOfBoundsException;
use Ritmo\Application\Schedule\FailureKind;
use Ritmo\Application\Schedule\LaunchResult;
use Ritmo\Application\Schedule\LaunchStatus;
use Ritmo\Application\Schedule\RunExecutor;
use Ritmo\Application\ShadowRun\CollectStage;
use Ritmo\Application\ShadowRun\RunOutcome;
use Ritmo\Application\ShadowRun\RunPipeline;
use Ritmo\Domain\Model\Property;
use Ritmo\Domain\Model\PropertyMode;
use Ritmo\Domain\Operations\RunKind;
use Ritmo\Infrastructure\Db\Repository\RunRepository;

/**
 * The runs of the scheduler and of "Run now", over the run pipeline (collect, watch, measure,
 * decide, validate, shadow, write, verify, report). The mode is fixed when the run starts: the
 * property's mode, or shadow when the emergency switch is engaged (the run reads, watches and
 * decides, and writes nothing). The launcher holds the property's run lock around every call.
 */
final class PipelineRunExecutor implements RunExecutor
{
    public function __construct(
        private readonly RunOpener $opener,
        private readonly RunRepository $runs,
        private readonly RunPipeline $pipeline,
    ) {
    }

    public function start(Property $property, RunKind $kind, bool $forcedShadow, DateTimeImmutable $today, DateTimeImmutable $deadline, Closure $heartbeat): LaunchResult
    {
        $context = $this->opener->open($property, $kind->value, $forcedShadow ? PropertyMode::Shadow : $property->mode, $today);
        if ($context instanceof RunOutcome) {
            return self::launch($context);
        }

        return self::launch($this->pipeline->continue($context, $deadline, $heartbeat));
    }

    public function resume(Property $property, int $runId, DateTimeImmutable $deadline, Closure $heartbeat): LaunchResult
    {
        $open = $this->runs->find($runId);
        if ($open === null || $open['property_id'] !== $property->id || $open['status'] !== RunRepository::RUNNING) {
            throw new OutOfBoundsException(sprintf('No open run %d of the property.', $runId));
        }

        return self::launch($this->pipeline->continue($this->opener->reopen($property, $open), $deadline, $heartbeat));
    }

    /**
     * A run that failed while reading (collect, or the booking read of the watch) failed because a
     * system could not be reached: the scheduler retries it once.
     */
    private static function launch(RunOutcome $outcome): LaunchResult
    {
        $status = match ($outcome->status) {
            RunOutcome::RUNNING => LaunchStatus::Running,
            RunOutcome::COMPLETED => LaunchStatus::Completed,
            RunOutcome::BUSY => LaunchStatus::Busy,
            RunOutcome::FAILED => LaunchStatus::Failed,
            default => LaunchStatus::Stopped,
        };
        $failure = match (true) {
            $status !== LaunchStatus::Failed => null,
            in_array($outcome->stage, ['', CollectStage::NAME], true) => FailureKind::Connector,
            default => FailureKind::Other,
        };

        return new LaunchResult($status, $outcome->runId, $failure, (string) $outcome->error);
    }
}
