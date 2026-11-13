<?php

declare(strict_types=1);

namespace Ritmo\Tests\Unit\Application\Schedule;

use Ritmo\Application\Schedule\FailureKind;
use Ritmo\Application\Schedule\LaunchResult;
use Ritmo\Application\Schedule\LaunchStatus;
use Ritmo\Application\Schedule\RunLauncher;
use Ritmo\Domain\Model\Property;
use Ritmo\Domain\Model\PropertyMode;
use Ritmo\Domain\Operations\RunKind;
use Ritmo\Domain\Support\Clock;
use Ritmo\Infrastructure\Db\Repository\RunRepository;

/**
 * A run launcher that records the runs in the runs table and ends them as scripted: "complete",
 * "running" (left open for the next tick), "connector" (failed: a system could not be reached),
 * "busy" (another run holds the lock) or "throw".
 */
final class FakeLauncher implements RunLauncher
{
    /** @var list<array{RunKind, bool}> kinds started and whether as forced shadow */
    public array $started = [];

    /** @var list<string> scripted outcomes of the next starts; empty: complete */
    public array $next = [];

    /** @var string how continueOpen ends an open run: "complete", "running", "day_changed" */
    public string $continueAs = 'complete';

    public int $continued = 0;

    public function __construct(private readonly RunRepository $runs, private readonly Clock $clock)
    {
    }

    public function continueOpen(Property $property, int $budgetSeconds): ?LaunchResult
    {
        $open = $this->open($property);
        if ($open === null) {
            return null;
        }
        ++$this->continued;

        return match ($this->continueAs) {
            'running' => new LaunchResult(LaunchStatus::Running, $open['id']),
            'day_changed' => $this->finish($open['id'], LaunchStatus::Failed, FailureKind::DayChanged),
            default => $this->finish($open['id'], LaunchStatus::Completed, null),
        };
    }

    public function stopOpen(Property $property, string $reason): ?LaunchResult
    {
        $open = $this->open($property);

        return $open === null ? null : $this->finish($open['id'], LaunchStatus::Stopped, null, $reason);
    }

    public function start(Property $property, RunKind $kind, bool $forcedShadow, int $budgetSeconds): LaunchResult
    {
        $outcome = array_shift($this->next) ?? 'complete';
        if ($outcome === 'busy') {
            return LaunchResult::busy();
        }
        if ($outcome === 'throw') {
            throw new \RuntimeException('The run broke.');
        }
        $this->started[] = [$kind, $forcedShadow];
        $id = $this->runs->start($property->id, $kind->value, PropertyMode::Shadow, null, $this->clock->now(), $this->clock->today($property->timezone));

        return match ($outcome) {
            'running' => new LaunchResult(LaunchStatus::Running, $id),
            'connector' => $this->finish($id, LaunchStatus::Failed, FailureKind::Connector),
            default => $this->finish($id, LaunchStatus::Completed, null),
        };
    }

    /**
     * @return list<string> the kinds started, in order
     */
    public function kinds(): array
    {
        return array_map(static fn (array $s): string => $s[0]->value, $this->started);
    }

    /**
     * @return array{id: int}|null
     */
    private function open(Property $property): ?array
    {
        $open = null;
        foreach (RunKind::cases() as $kind) {
            $open ??= $this->runs->openRun($property->id, $kind->value);
        }

        return $open;
    }

    private function finish(int $id, LaunchStatus $status, ?FailureKind $failure, string $note = ''): LaunchResult
    {
        $note = $note !== '' ? $note : ($failure?->value ?? '');
        $this->runs->finish($id, $status->value, [], $this->clock->now(), $note === '' ? null : $note);

        return new LaunchResult($status, $id, $failure, $note);
    }
}
