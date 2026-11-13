<?php

declare(strict_types=1);

namespace Ritmo\Tests\Unit\Application\Schedule;

use Ritmo\Application\EmergencySwitch;
use Ritmo\Application\Schedule\RunLauncher;
use Ritmo\Application\Schedule\ScheduleHousekeeping;
use Ritmo\Application\Schedule\ScheduleSource;
use Ritmo\Application\Schedule\SchedulerTick;
use Ritmo\Application\Schedule\TickReport;
use Ritmo\Domain\Model\Property;
use Ritmo\Domain\Operations\ScheduleConfig;
use Ritmo\Domain\Operations\SlotRecord;
use Ritmo\Infrastructure\Db\PdoDatabase;
use Ritmo\Infrastructure\Db\Repository\PropertyRepository;
use Ritmo\Infrastructure\Db\Repository\RunRepository;
use Ritmo\Infrastructure\Lock\DbLock;
use Ritmo\Infrastructure\Schedule\BookingMovementRepository;
use Ritmo\Infrastructure\Schedule\OperationStateRepository;
use Ritmo\Infrastructure\Schedule\ScheduledRunRepository;
use Ritmo\Infrastructure\Store\ArrayStore;
use Ritmo\Tests\Fakes\MovableClock;
use Ritmo\Tests\Fakes\RecordingLogger;
use Ritmo\Tests\Unit\Application\Alerts\AlertsKit;
use Ritmo\Tests\Unit\Infrastructure\Schedule\OpsDatabase;

/**
 * One site for the scheduler tests: SQLite with every table, a movable clock, the scripted
 * launcher and the real repositories, lock, alerts and emergency switch.
 */
final class ScheduleWorld
{
    public readonly PdoDatabase $db;

    public readonly MovableClock $clock;

    public readonly ArrayStore $store;

    public readonly ScheduledRunRepository $slots;

    public readonly OperationStateRepository $states;

    public readonly RunRepository $runs;

    public readonly DbLock $locks;

    public readonly EmergencySwitch $switch;

    public readonly FakeLauncher $launcher;

    public readonly AlertsKit $alerts;

    public readonly RecordingLogger $logger;

    public ScheduleConfig $schedule;

    /** A launcher that replaces the scripted one in the ticks (the real locked launcher). */
    public ?RunLauncher $runLauncher = null;

    /**
     * @param array<string, mixed> $schedule the schedule section
     */
    public function __construct(string $now, array $schedule = ['full_time' => '07:30', 'light_times' => ['12:30', '18:30']], string $timezone = 'America/Sao_Paulo')
    {
        $this->db = OpsDatabase::create();
        $this->clock = new MovableClock($now);
        $this->store = new ArrayStore();
        $this->slots = new ScheduledRunRepository($this->db, $this->clock);
        $this->states = new OperationStateRepository($this->db, $this->clock);
        $this->runs = new RunRepository($this->db);
        $this->locks = new DbLock($this->db, $this->clock);
        $this->switch = new EmergencySwitch($this->store);
        $this->launcher = new FakeLauncher($this->runs, $this->clock);
        $this->alerts = new AlertsKit($this->db, $this->clock);
        $this->logger = new RecordingLogger();
        $this->schedule = ScheduleConfig::fromArray($schedule);
        (new PropertyRepository($this->db, $this->clock))->save(new Property('inn', 'Sample inn', $timezone, 'BRL'));
    }

    public function tick(): TickReport
    {
        $source = new class ($this) implements ScheduleSource {
            public function __construct(private readonly ScheduleWorld $world)
            {
            }

            public function schedule(Property $property): ScheduleConfig
            {
                return $this->world->schedule;
            }
        };

        return (new SchedulerTick(
            new PropertyRepository($this->db, $this->clock),
            $this->slots,
            $this->states,
            $this->runs,
            $this->locks,
            $this->store,
            $this->switch,
            $this->runLauncher ?? $this->launcher,
            $source,
            $this->alerts->service,
            new ScheduleHousekeeping($this->slots, new BookingMovementRepository($this->db), $this->store, $this->clock),
            $this->clock,
            $this->logger,
        ))->run();
    }

    /**
     * Moves the clock to an instant (forward or back).
     */
    public function at(string $instant): void
    {
        $this->clock->advance((new \DateTimeImmutable($instant))->getTimestamp() - $this->clock->now()->getTimestamp());
    }

    /**
     * @return array<string, string> slot local key and kind => status
     */
    public function slotStatuses(): array
    {
        $out = [];
        foreach (array_reverse($this->slots->latest('inn', 200)) as $record) {
            $out[$record->slot->key()] = $record->status->value;
        }

        return $out;
    }

    public function slot(string $key): ?SlotRecord
    {
        foreach ($this->slots->latest('inn', 200) as $record) {
            if ($record->slot->key() === $key) {
                return $record;
            }
        }

        return null;
    }

    /**
     * @return list<string> dedupe keys of the open alerts of the given codes
     */
    public function openAlerts(string ...$codes): array
    {
        return array_map(static fn ($a): string => $a->dedupeKey, $this->alerts->ledger->active('inn', $codes));
    }
}
