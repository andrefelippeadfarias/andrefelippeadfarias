<?php

declare(strict_types=1);

namespace Ritmo\Tests\Unit\Application;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Ritmo\Application\ShadowRun\RunContext;
use Ritmo\Application\ShadowRun\RunOutcome;
use Ritmo\Application\ShadowRun\RunPipeline;
use Ritmo\Application\ShadowRun\ShadowRunService;
use Ritmo\Application\ShadowRun\Stage;
use Ritmo\Application\ShadowRun\StageResult;
use Ritmo\Domain\Config\RuleSet;
use Ritmo\Domain\Model\Property;
use Ritmo\Domain\Model\PropertyMode;
use Ritmo\Domain\Support\DateRange;
use Ritmo\Domain\Support\Days;
use Ritmo\Infrastructure\Db\Repository\PlanItemRepository;
use Ritmo\Infrastructure\Db\Repository\RunRepository;
use RuntimeException;

final class RunPipelineTest extends TestCase
{
    private ShadowRunWorld $world;

    protected function setUp(): void
    {
        $this->world = new ShadowRunWorld();
    }

    public function testStagesNeedUniqueNames(): void
    {
        $stage = self::stage('collect');

        self::assertSame(['collect', 'measure'], $this->pipeline([$stage, self::stage('measure')])->stageNames());
        $this->expectException(InvalidArgumentException::class);
        $this->pipeline([$stage, self::stage('collect')]);
    }

    public function testARunStoppedAtAStageThePipelineDoesNotKnowIsAnError(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->pipeline([self::stage('collect')])->continue($this->context('vanished'), $this->world->clock->now());
    }

    public function testAtLeastOneStageRunsAndNoNewStageStartsAfterTheDeadline(): void
    {
        $pipeline = $this->pipeline([self::stage('a'), self::stage('b'), self::stage('c')]);
        $context = $this->context();

        $outcome = $pipeline->continue($context, $this->world->clock->now()->modify('-1 hour'));

        self::assertSame(RunOutcome::RUNNING, $outcome->status);
        self::assertSame('a', $outcome->stage);
        self::assertSame(['a' => ['done' => 'a']], $this->world->runs->find($context->runId)['summary'] ?? null);
        self::assertSame(['last' => 'a'], $this->world->runs->state($context->runId));
    }

    public function testWhatAStageStoresAndItsMarkerAreOneTransaction(): void
    {
        $context = $this->context();
        $failing = new class ($this->world->planItems) implements Stage {
            public function __construct(private readonly PlanItemRepository $planItems)
            {
            }

            public function name(): string
            {
                return 'store';
            }

            public function run(RunContext $context): StageResult
            {
                return new StageResult(['rows' => 1], [], function () use ($context): void {
                    $this->planItems->add($context->runId, 'inn', 'STD', Days::date('2027-03-02'), 'floor', null, 450.0);
                    throw new RuntimeException('The disk is full.');
                });
            }
        };

        $outcome = $this->pipeline([$failing])->continue($context, $this->world->clock->now());

        self::assertSame(RunOutcome::FAILED, $outcome->status);
        self::assertSame('Stage "store": Unexpected error (RuntimeException).', $outcome->error, 'a message from outside the plugin is not shown');
        self::assertSame([], $this->world->planItems->forRun($context->runId), 'the row written before the failure was rolled back');
        self::assertSame('', $this->world->runs->find($context->runId)['stage'] ?? null);
        self::assertSame(RunRepository::FAILED, $this->world->runs->find($context->runId)['status'] ?? null);
    }

    public function testARunThatLostItsLockStaysOpen(): void
    {
        $context = $this->context();

        $outcome = $this->pipeline([self::stage('a')])->continue($context, $this->world->clock->now(), static fn (): bool => false);

        self::assertSame(RunOutcome::BUSY, $outcome->status);
        self::assertSame(RunRepository::RUNNING, $this->world->runs->find($context->runId)['status'] ?? null);
    }

    /**
     * @param list<Stage> $stages
     */
    private function pipeline(array $stages): RunPipeline
    {
        return new RunPipeline($stages, $this->world->runs, $this->world->db, $this->world->switch, $this->world->clock, $this->world->logger);
    }

    private function context(string $stage = ''): RunContext
    {
        $today = Days::date('2027-03-01');
        $runId = $this->world->runs->start('inn', ShadowRunService::KIND, PropertyMode::Shadow, null, $this->world->clock->now(), $today);

        return new RunContext($runId, new Property('inn', 'Example Inn', 'UTC', 'BRL'), ShadowRunWorld::rooms(), RuleSet::defaults(), $today, DateRange::nights($today, 3), $stage);
    }

    private static function stage(string $name): Stage
    {
        return new class ($name) implements Stage {
            public function __construct(private readonly string $name)
            {
            }

            public function name(): string
            {
                return $this->name;
            }

            public function run(RunContext $context): StageResult
            {
                return new StageResult(['done' => $this->name], ['last' => $this->name]);
            }
        };
    }
}
