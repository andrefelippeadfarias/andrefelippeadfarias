<?php

declare(strict_types=1);

namespace Ritmo\Application\ShadowRun;

use Closure;
use DateTimeImmutable;
use InvalidArgumentException;
use ReflectionClass;
use Ritmo\Application\EmergencySwitch;
use Ritmo\Contracts\Logger;
use Ritmo\Domain\Model\PropertyMode;
use Ritmo\Domain\Support\Clock;
use Ritmo\Infrastructure\Db\Database;
use Ritmo\Infrastructure\Db\Repository\RunRepository;
use Throwable;

/**
 * Runs the stages of a run in order, one short stage at a time, and records each completed
 * stage in the run together with what it stored, in one transaction. Before every stage it
 * checks the emergency switch (a run that may write stops) and that the run still holds its lock;
 * it stops at the deadline and the run resumes later from the next stage.
 */
final class RunPipeline
{
    /** @var list<Stage> */
    private readonly array $stages;

    /**
     * @param list<Stage> $stages in the order they run; names must be unique
     */
    public function __construct(
        array $stages,
        private readonly RunRepository $runs,
        private readonly Database $db,
        private readonly EmergencySwitch $switch,
        private readonly Clock $clock,
        private readonly Logger $logger,
    ) {
        $names = array_map(static fn (Stage $stage): string => $stage->name(), $stages);
        if ($stages === [] || count(array_unique($names)) !== count($names) || in_array('', $names, true)) {
            throw new InvalidArgumentException('A pipeline needs stages with unique, non-empty names.');
        }
        $this->stages = array_values($stages);
    }

    /**
     * @return list<string>
     */
    public function stageNames(): array
    {
        return array_map(static fn (Stage $stage): string => $stage->name(), $this->stages);
    }

    /**
     * Runs the stages after the last one the context completed.
     *
     * @param DateTimeImmutable    $deadline  no new stage starts once the clock reaches it (at least one stage runs)
     * @param (Closure(): bool)|null $keepAlive called before each stage; false means the run lost its lock
     */
    public function continue(RunContext $context, DateTimeImmutable $deadline, ?Closure $keepAlive = null): RunOutcome
    {
        $ran = 0;
        $context = $context->withHeartbeat($keepAlive);
        while (($stage = $this->next($context->stage)) !== null) {
            if ($ran > 0 && $this->clock->now() >= $deadline) {
                return new RunOutcome(RunOutcome::RUNNING, $context->runId, $context->stage);
            }
            if ($this->switch->isEngaged() && !$stage instanceof AfterWriteStage && self::stopsUnderSwitch($context)) {
                return $this->end($context, RunRepository::STOPPED, sprintf('Stopped by the emergency switch before the stage "%s".', $stage->name()));
            }
            if ($keepAlive !== null && !$keepAlive()) {
                return new RunOutcome(RunOutcome::BUSY, $context->runId, $context->stage, 'The run lost its lock; it resumes from the next stage later.');
            }

            $started = $this->clock->now();
            try {
                $result = $stage->run($context);
                $summary = $context->summary;
                $summary[$stage->name()] = $result->summary;
                $state = array_replace($context->state, $result->state);
                $this->db->transaction(function () use ($context, $stage, $result, $summary, $state): void {
                    if ($result->persist !== null) {
                        ($result->persist)();
                    }
                    $this->runs->advance($context->runId, $stage->name(), $summary, $state);
                });
            } catch (Throwable $failure) {
                $this->logger->error('A stage of the run failed.', [
                    'run_id' => $context->runId,
                    'property_id' => $context->property->id,
                    'stage' => $stage->name(),
                    'error_class' => $failure::class,
                ]);

                return $this->end($context, RunRepository::FAILED, sprintf('Stage "%s": %s', $stage->name(), self::safeMessage($failure)));
            }

            $this->logger->info('A stage of the run completed.', [
                'run_id' => $context->runId,
                'property_id' => $context->property->id,
                'stage' => $stage->name(),
                'duration_ms' => (int) (((float) $this->clock->now()->format('U.u') - (float) $started->format('U.u')) * 1000),
            ]);
            $context = $context->after($stage->name(), $summary, $state);
            ++$ran;
        }

        return $this->end($context, RunRepository::COMPLETED, null);
    }

    /**
     * The emergency switch stops every run that may write, and the shadow run a person starts from
     * the screen; a scheduled run in shadow mode (forced to shadow by the switch, or of a property
     * in shadow) goes on reading and watching: it writes nothing (operations spec O9, D7).
     */
    private static function stopsUnderSwitch(RunContext $context): bool
    {
        return $context->kind === ShadowRunService::KIND || $context->mode !== PropertyMode::Shadow;
    }

    private function next(string $completed): ?Stage
    {
        if ($completed === '') {
            return $this->stages[0];
        }
        foreach ($this->stages as $index => $stage) {
            if ($stage->name() === $completed) {
                return $this->stages[$index + 1] ?? null;
            }
        }

        throw new InvalidArgumentException(sprintf('The run stopped at an unknown stage "%s".', $completed));
    }

    private function end(RunContext $context, string $status, ?string $error): RunOutcome
    {
        $this->runs->finish($context->runId, $status, $context->summary, $this->clock->now(), $error);
        $this->logger->log($status === RunRepository::COMPLETED ? Logger::INFO : Logger::WARNING, 'A run ended.', [
            'run_id' => $context->runId,
            'property_id' => $context->property->id,
            'status' => $status,
        ]);

        return new RunOutcome($status, $context->runId, $context->stage, $error);
    }

    /**
     * The plugin's own exceptions carry sentences safe to show; anything else is described by
     * its kind only.
     */
    private static function safeMessage(Throwable $failure): string
    {
        return str_starts_with($failure::class, 'Ritmo\\')
            ? $failure->getMessage()
            : 'Unexpected error (' . (new ReflectionClass($failure))->getShortName() . ').';
    }
}
