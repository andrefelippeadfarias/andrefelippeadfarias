<?php

declare(strict_types=1);

namespace Ritmo\Tests\Unit\Application\Schedule;

use Closure;
use DateTimeImmutable;
use Ritmo\Application\Schedule\LaunchResult;
use Ritmo\Application\Schedule\LaunchStatus;
use Ritmo\Application\Schedule\RunExecutor;
use Ritmo\Domain\Model\Property;
use Ritmo\Domain\Model\PropertyMode;
use Ritmo\Domain\Operations\RunKind;
use Ritmo\Domain\Support\Clock;
use Ritmo\Infrastructure\Db\Repository\RunRepository;

/**
 * A run executor that opens its runs in the runs table and ends each piece of work as scripted:
 * "complete" or "running" (left open for the next request). A hook runs in the middle of the
 * work, while the launcher holds the property's run lock (to try a second run meanwhile).
 */
final class ScriptedExecutor implements RunExecutor
{
    /** @var list<string> outcomes of the next pieces of work (start or resume); empty: complete */
    public array $next = [];

    /** @var list<array{string, mixed...}> what was called: ['start', kind, forcedShadow, today, deadline] or ['resume', runId, deadline] */
    public array $calls = [];

    /** @var list<bool> answers of the heartbeat, one per piece of work */
    public array $heartbeats = [];

    /** @var (Closure(): void)|null */
    public ?Closure $during = null;

    public function __construct(private readonly RunRepository $runs, private readonly Clock $clock)
    {
    }

    public function start(Property $property, RunKind $kind, bool $forcedShadow, DateTimeImmutable $today, DateTimeImmutable $deadline, Closure $heartbeat): LaunchResult
    {
        $this->calls[] = ['start', $kind, $forcedShadow, $today->format('Y-m-d'), $deadline];
        $id = $this->runs->start($property->id, $kind->value, PropertyMode::Shadow, null, $this->clock->now(), $today);

        return $this->work($id, $heartbeat);
    }

    public function resume(Property $property, int $runId, DateTimeImmutable $deadline, Closure $heartbeat): LaunchResult
    {
        $this->calls[] = ['resume', $runId, $deadline];

        return $this->work($runId, $heartbeat);
    }

    /**
     * @return list<string> 'start:<kind>' or 'resume:<run id>', in order
     */
    public function trail(): array
    {
        return array_map(static fn (array $call): string => $call[0] === 'start' ? 'start:' . $call[1]->value : 'resume:' . $call[1], $this->calls);
    }

    private function work(int $runId, Closure $heartbeat): LaunchResult
    {
        if ($this->during !== null) {
            $during = $this->during;
            $this->during = null;
            $during();
        }
        $this->heartbeats[] = $heartbeat();
        if ((array_shift($this->next) ?? 'complete') === 'running') {
            return new LaunchResult(LaunchStatus::Running, $runId);
        }
        $this->runs->finish($runId, RunRepository::COMPLETED, [], $this->clock->now());

        return new LaunchResult(LaunchStatus::Completed, $runId);
    }
}
