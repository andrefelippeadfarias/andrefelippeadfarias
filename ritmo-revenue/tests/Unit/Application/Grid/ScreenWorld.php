<?php

declare(strict_types=1);

namespace Ritmo\Tests\Unit\Application\Grid;

use DateTimeImmutable;
use Ritmo\Application\Dashboard\OccupancyQuery;
use Ritmo\Application\Dashboard\Overview\OverviewQuery;
use Ritmo\Application\Dashboard\StatusQuery;
use Ritmo\Application\EmergencySwitch;
use Ritmo\Application\Grid\LastPlan;
use Ritmo\Application\Grid\NightDetailQuery;
use Ritmo\Application\Grid\NightGridQuery;
use Ritmo\Domain\Model\BookingRecord;
use Ritmo\Domain\Model\BookingStatus;
use Ritmo\Domain\Model\InventoryNight;
use Ritmo\Domain\Model\Property;
use Ritmo\Domain\Model\PropertyMode;
use Ritmo\Domain\Model\Restriction;
use Ritmo\Domain\Model\RoomType;
use Ritmo\Domain\Support\DateRange;
use Ritmo\Domain\Support\Days;
use Ritmo\Domain\Support\FixedClock;
use Ritmo\Infrastructure\Db\Migration\Migrations;
use Ritmo\Infrastructure\Db\Migration\Migrator;
use Ritmo\Infrastructure\Db\PdoDatabase;
use Ritmo\Infrastructure\Db\Query\Screens\AlertListReader;
use Ritmo\Infrastructure\Db\Query\Screens\TodoRepository;
use Ritmo\Infrastructure\Db\Repository\AlertRepository;
use Ritmo\Infrastructure\Db\Repository\BookingRepository;
use Ritmo\Infrastructure\Db\Repository\ChangeLogRepository;
use Ritmo\Infrastructure\Db\Repository\DetectedFreezeRepository;
use Ritmo\Infrastructure\Db\Repository\InventorySnapshotRepository;
use Ritmo\Infrastructure\Db\Repository\OrderRepository;
use Ritmo\Infrastructure\Db\Repository\PlanApprovalRepository;
use Ritmo\Infrastructure\Db\Repository\PlanItemRepository;
use Ritmo\Infrastructure\Db\Repository\PropertyRepository;
use Ritmo\Infrastructure\Db\Repository\RoomTypeRepository;
use Ritmo\Infrastructure\Db\Repository\RuleSetRepository;
use Ritmo\Infrastructure\Db\Repository\RunRepository;
use Ritmo\Infrastructure\Db\Repository\StepLedgerRepository;
use Ritmo\Infrastructure\Schedule\OperationStateRepository;
use Ritmo\Infrastructure\Store\ArrayStore;

/**
 * A property with rooms, PMS calendar, bookings and a completed run with its plan, in an SQLite
 * database in memory, for the read models of the screens. Invented data only.
 */
final class ScreenWorld
{
    public const TODAY = '2026-11-13';

    public readonly PdoDatabase $raw;

    public readonly CountingDatabase $db;

    public readonly FixedClock $clock;

    public readonly ArrayStore $settings;

    public function __construct(string $now = '2026-11-13T13:00:00Z')
    {
        $this->raw = PdoDatabase::inMemory();
        (new Migrator($this->raw, new ArrayStore(), Migrations::all()))->migrate();
        $this->db = new CountingDatabase($this->raw);
        $this->clock = new FixedClock($now);
        $this->settings = new ArrayStore();
    }

    public function property(string $id = 'inn', PropertyMode $mode = PropertyMode::Shadow): Property
    {
        $property = new Property($id, 'Example Inn', 'America/Sao_Paulo', 'BRL', $mode);
        $this->properties()->save($property);

        return $property;
    }

    public function room(string $code, int $units = 2, float $base = 600.0, string $property = 'inn'): RoomType
    {
        $room = new RoomType($property, $code, 'Room ' . $code, $units, 'pms-' . $code, 'pl-' . $code, $base, 400.0, 1200.0, 400.0);
        $this->roomTypes()->save($room);

        return $room;
    }

    /**
     * @param array<string, array{0: int, 1?: Restriction}> $nights "YYYY-MM-DD" => [available, restriction]
     */
    public function calendar(string $room, int $count, int $available = 2, array $nights = []): void
    {
        $rows = [];
        foreach (DateRange::nights(Days::date(self::TODAY), $count) as $night) {
            [$free, $restriction] = ($nights[Days::key($night)] ?? []) + [$available, Restriction::None];
            $rows[] = new InventoryNight($night, $free, 2, null, $restriction, 500.0);
        }
        (new InventorySnapshotRepository($this->raw))->store($room, $rows, $this->clock->now()->modify('-1 hour'));
    }

    public function booking(string $ref, string $room, string $arrival, string $departure, float $total = 1000.0, string $channel = 'booking'): void
    {
        (new BookingRepository($this->raw))->upsert('beds24', new BookingRecord(
            $ref,
            $room,
            Days::date($arrival),
            Days::date($departure),
            BookingStatus::Confirmed,
            $channel,
            'channel',
            $total,
            $this->clock->now()->modify('-3 days'),
            null,
            null,
        ), $this->clock->now());
    }

    /**
     * A completed run whose plan covers $count nights of each room from today.
     *
     * @param list<string>                  $rooms
     * @param array<string, string>         $statuses "room|YYYY-MM-DD" => plan item status (default no_change)
     */
    public function run(array $rooms, int $count, array $statuses = [], string $kind = 'shadow', string $property = 'inn'): int
    {
        $runs = new RunRepository($this->raw);
        $id = $runs->start($property, $kind, PropertyMode::Shadow, null, $this->clock->now()->modify('-50 minutes'));
        $items = new PlanItemRepository($this->raw, $this->clock);
        $paces = ['ahead', 'on_pace', 'behind', 'very_behind'];
        $index = 0;
        foreach ($rooms as $room) {
            foreach (DateRange::nights(Days::date(self::TODAY), $count) as $night) {
                $status = $statuses[$room . '|' . Days::key($night)] ?? 'no_change';
                $pace = $paces[$index++ % 4];
                $details = [
                    'rule_trace' => [['code' => 'pace_status', 'params' => ['status' => $pace]]],
                    'pace_status' => $pace,
                    'current_price' => 480.0,
                    'min_percent' => $status === 'would_write' ? -30 : -20,
                    'max_percent' => 50,
                    'issues' => $status === 'rejected' ? ['FLOOR_BELOW_ABSOLUTE'] : [],
                ];
                $items->add($id, $property, $room, $night, 'floor', 480.0, $status === 'would_write' ? 420.0 : 480.0, 'PACE_STEP', $details, status: $status);
                $items->add($id, $property, $room, $night, 'ceiling', 900.0, 900.0, 'PACE_STEP', $details, status: $status === 'rejected' ? 'rejected' : 'no_change');
            }
        }
        $runs->finish($id, RunRepository::COMPLETED, ['report' => ['lines' => ['Done.']]], $this->clock->now()->modify('-49 minutes'));

        return $id;
    }

    public function grid(): NightGridQuery
    {
        return new NightGridQuery(
            $this->properties(),
            $this->roomTypes(),
            new RuleSetRepository($this->db, $this->clock),
            new InventorySnapshotRepository($this->db),
            new BookingRepository($this->db),
            new LastPlan(new RunRepository($this->db), new PlanItemRepository($this->db, $this->clock)),
            new DetectedFreezeRepository($this->db),
            new OrderRepository($this->db, $this->clock),
            new AlertRepository($this->db, $this->clock),
            new ChangeLogRepository($this->db),
            $this->clock,
        );
    }

    public function detail(): NightDetailQuery
    {
        return new NightDetailQuery(
            $this->grid(),
            $this->roomTypes(),
            new BookingRepository($this->db),
            new DetectedFreezeRepository($this->db),
            new ChangeLogRepository($this->db),
            new StepLedgerRepository($this->db),
            new PlanItemRepository($this->db, $this->clock),
        );
    }

    public function status(): StatusQuery
    {
        return new StatusQuery($this->properties(), new EmergencySwitch($this->settings), new OperationStateRepository($this->db, $this->clock), $this->approvals(), new RunRepository($this->db), $this->clock);
    }

    public function overview(): OverviewQuery
    {
        return new OverviewQuery(
            $this->properties(),
            $this->roomTypes(),
            new RuleSetRepository($this->db, $this->clock),
            new InventorySnapshotRepository($this->db),
            new BookingRepository($this->db),
            new OccupancyQuery($this->properties(), $this->roomTypes(), new RuleSetRepository($this->db, $this->clock), new InventorySnapshotRepository($this->db), $this->clock),
            new LastPlan(new RunRepository($this->db), new PlanItemRepository($this->db, $this->clock)),
            $this->status(),
            new AlertListReader($this->db),
            $this->todos(),
            $this->clock,
        );
    }

    public function todos(): TodoRepository
    {
        return new TodoRepository($this->db);
    }

    public function properties(): PropertyRepository
    {
        return new PropertyRepository($this->db, $this->clock);
    }

    public function roomTypes(): RoomTypeRepository
    {
        return new RoomTypeRepository($this->db, $this->clock);
    }

    public function approvals(): PlanApprovalRepository
    {
        return new PlanApprovalRepository($this->db);
    }

    public function alerts(): AlertRepository
    {
        return new AlertRepository($this->raw, $this->clock);
    }

    public function freezes(): DetectedFreezeRepository
    {
        return new DetectedFreezeRepository($this->raw);
    }

    public function night(string $ymd): DateTimeImmutable
    {
        return Days::date($ymd);
    }
}
