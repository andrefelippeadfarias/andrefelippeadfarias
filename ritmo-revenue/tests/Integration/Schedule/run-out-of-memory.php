<?php
/**
 * Child process of RunStoppedByPhpTest: a run that PHP stops for lack of memory in the middle of
 * a stage, inside the stage's database transaction, as RunPipeline works. Argument: the SQLite file.
 */

declare(strict_types=1);

use Ritmo\Application\Schedule\LaunchResult;
use Ritmo\Application\Schedule\LaunchStatus;
use Ritmo\Application\Schedule\LockedRunLauncher;
use Ritmo\Application\Schedule\RunExecutor;
use Ritmo\Domain\Model\Property;
use Ritmo\Domain\Model\PropertyMode;
use Ritmo\Domain\Operations\RunKind;
use Ritmo\Infrastructure\Db\Database;
use Ritmo\Infrastructure\Db\PdoDatabase;
use Ritmo\Infrastructure\Db\Repository\AlertRepository;
use Ritmo\Infrastructure\Db\Repository\PropertyRepository;
use Ritmo\Infrastructure\Db\Repository\RunRepository;
use Ritmo\Infrastructure\Lock\DbLock;
use Ritmo\Tests\Fakes\MovableClock;
use Ritmo\Tests\Fakes\RecordingLogger;

require dirname(__DIR__, 2) . '/bootstrap.php';

$db = new PdoDatabase(new PDO('sqlite:' . $argv[1]));
$clock = new MovableClock('2026-11-12T10:31:00Z');
$runs = new RunRepository($db);
$executor = new class ($db, $runs, $clock) implements RunExecutor {
    public function __construct(private readonly Database $db, private readonly RunRepository $runs, private readonly MovableClock $clock)
    {
    }

    public function start(Property $property, RunKind $kind, bool $forcedShadow, DateTimeImmutable $today, DateTimeImmutable $deadline, Closure $heartbeat): LaunchResult
    {
        $id = $this->runs->start($property->id, $kind->value, PropertyMode::Shadow, null, $this->clock->now(), $today);

        return $this->db->transaction(function () use ($id): LaunchResult {
            $this->runs->advance($id, 'calendar', ['nights' => 365], []);
            $hog = [];
            while (true) {
                $hog[] = str_repeat('x', 1 << 20);
            }
        });
    }

    public function resume(Property $property, int $runId, DateTimeImmutable $deadline, Closure $heartbeat): LaunchResult
    {
        return new LaunchResult(LaunchStatus::Completed, $runId);
    }
};
$launcher = new LockedRunLauncher(
    $runs,
    new DbLock($db, $clock),
    $executor,
    $clock,
    new RecordingLogger(),
    $db,
    static fn (string $propertyId, int $runId, string $cause) => (new AlertRepository($db, $clock))->raise('run_stopped', AlertRepository::WARNING, 'PHP stopped a run.', ['cause' => $cause], $propertyId, '', null, 'run_stopped:' . $propertyId, $runId),
);
$property = (new PropertyRepository($db, $clock))->find('inn') ?? throw new RuntimeException('inn');
$launcher->start($property, RunKind::Manual, false, 20);
echo "not reached\n";
