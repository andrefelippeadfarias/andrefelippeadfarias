<?php

declare(strict_types=1);

namespace Ritmo\Tests\Unit\Application;

use DateTimeImmutable;
use Ritmo\Application\ConnectorSource;
use Ritmo\Application\EmergencySwitch;
use Ritmo\Application\Run\RunOpener;
use Ritmo\Application\ShadowRun\CollectStage;
use Ritmo\Application\ShadowRun\DecideStage;
use Ritmo\Application\ShadowRun\MeasureStage;
use Ritmo\Application\ShadowRun\RunInputsReader;
use Ritmo\Application\ShadowRun\RunPipeline;
use Ritmo\Application\ShadowRun\ShadowRunService;
use Ritmo\Application\ShadowRun\ShadowStage;
use Ritmo\Application\ShadowRun\Stage;
use Ritmo\Application\ShadowRun\ValidateStage;
use Ritmo\Domain\Config\RuleSet;
use Ritmo\Domain\Model\AnchorOrigin;
use Ritmo\Domain\Model\BookingRecord;
use Ritmo\Domain\Model\BookingStatus;
use Ritmo\Domain\Model\DateOverride;
use Ritmo\Domain\Model\InventoryNight;
use Ritmo\Domain\Model\ListingInfo;
use Ritmo\Domain\Model\MarketAnchor;
use Ritmo\Domain\Model\PriceNight;
use Ritmo\Domain\Model\Property;
use Ritmo\Domain\Model\Restriction;
use Ritmo\Domain\Model\RoomType;
use Ritmo\Domain\Model\SyncStatus;
use Ritmo\Domain\Support\DateRange;
use Ritmo\Domain\Support\Days;
use Ritmo\Infrastructure\Db\PdoDatabase;
use Ritmo\Infrastructure\Db\Repository\AlertRepository;
use Ritmo\Infrastructure\Db\Repository\BookingRepository;
use Ritmo\Infrastructure\Db\Repository\InventorySnapshotRepository;
use Ritmo\Infrastructure\Db\Repository\MarketAnchorRepository;
use Ritmo\Infrastructure\Db\Repository\OrderRepository;
use Ritmo\Infrastructure\Db\Repository\PlanItemRepository;
use Ritmo\Infrastructure\Db\Repository\PropertyRepository;
use Ritmo\Infrastructure\Db\Repository\RoomTypeRepository;
use Ritmo\Infrastructure\Db\Repository\RuleSetRepository;
use Ritmo\Infrastructure\Db\Repository\RunPayloadRepository;
use Ritmo\Infrastructure\Db\Repository\RunRepository;
use Ritmo\Infrastructure\Db\Repository\StepLedgerRepository;
use Ritmo\Infrastructure\Lock\DbLock;
use Ritmo\Infrastructure\Log\MemoryLogger;
use Ritmo\Infrastructure\Store\ArrayStore;
use Ritmo\Tests\Fakes\InMemoryPmsConnector;
use Ritmo\Tests\Fakes\InMemoryPricingConnector;
use Ritmo\Tests\Fakes\MovableClock;
use Ritmo\Tests\Fakes\StaticConnectorSource;
use Ritmo\Tests\Unit\Infrastructure\Support\MigratedDatabase;

/**
 * An invented inn for the tests of the shadow run, on an in-memory database with every table:
 * room STD (4 units) and STE (2 units) mapped to the PMS and the pricing system, room LOFT not
 * mapped; sixty nights from 2027-03-01 with prices in the PMS; night 2027-03-03 of STD sold out and
 * 2027-03-05 overbooked;
 * the listing of STE does not push prices; market anchors for weekday nights only, so weekend
 * nights have no market data.
 */
final class ShadowRunWorld
{
    public const PROPERTY = 'inn';

    public const TIMEZONE = 'America/Sao_Paulo';

    public const FIRST_NIGHT = '2027-03-01';

    /** Nights a run decides with the neutral rules (plan.horizon_nights). */
    public const HORIZON_NIGHTS = 60;

    public readonly PdoDatabase $db;

    public readonly MovableClock $clock;

    public readonly ArrayStore $store;

    public readonly MemoryLogger $logger;

    public readonly RunRepository $runs;

    public readonly PlanItemRepository $planItems;

    public readonly AlertRepository $alerts;

    public readonly InventorySnapshotRepository $snapshots;

    public readonly BookingRepository $bookings;

    public readonly MarketAnchorRepository $anchors;

    public readonly DbLock $locks;

    public readonly EmergencySwitch $switch;

    public InMemoryPmsConnector $pms;

    public InMemoryPricingConnector $pricing;

    public function __construct(bool $switchConstant = false)
    {
        $this->db = MigratedDatabase::create();
        $this->clock = new MovableClock('2027-03-01T13:00:00Z');
        $this->store = new ArrayStore();
        $this->logger = new MemoryLogger();
        $this->runs = new RunRepository($this->db);
        $this->planItems = new PlanItemRepository($this->db, $this->clock);
        $this->alerts = new AlertRepository($this->db, $this->clock);
        $this->snapshots = new InventorySnapshotRepository($this->db);
        $this->bookings = new BookingRepository($this->db);
        $this->anchors = new MarketAnchorRepository($this->db, $this->clock);
        $this->locks = new DbLock($this->db, $this->clock);
        $this->switch = new EmergencySwitch($this->store, $switchConstant);

        (new PropertyRepository($this->db, $this->clock))->save(new Property(self::PROPERTY, 'Example Inn', self::TIMEZONE, 'BRL'));
        $rooms = new RoomTypeRepository($this->db, $this->clock);
        foreach (self::rooms() as $room) {
            $rooms->save($room);
        }
        $rooms->save(new RoomType(self::PROPERTY, 'LOFT', 'Loft', 1, '', '', 900.0, 700.0, 3000.0, 650.0));
        (new RuleSetRepository($this->db, $this->clock))->save(RuleSet::defaults(), 'Defaults');
        $this->anchors->saveMany(self::PROPERTY, self::weekdayAnchors());

        $this->pms = self::pms();
        $this->pricing = self::pricing();
    }

    /**
     * @return list<RoomType>
     */
    public static function rooms(): array
    {
        return [
            new RoomType(self::PROPERTY, 'STD', 'Standard', 4, '902001', 'L-STD', 480.0, 320.0, 1600.0, 300.0),
            new RoomType(self::PROPERTY, 'STE', 'Suite', 2, '902002', 'L-STE', 700.0, 450.0, 2400.0, 420.0),
        ];
    }

    public static function nights(): DateRange
    {
        return DateRange::nights(Days::date(self::FIRST_NIGHT), self::HORIZON_NIGHTS);
    }

    public static function isWeekend(DateTimeImmutable $night): bool
    {
        return in_array(Days::weekday($night), RuleSet::defaults()->weekendDays(), true);
    }

    public static function pms(): InMemoryPmsConnector
    {
        $inventory = [];
        foreach (self::nights() as $key => $night) {
            $weekend = self::isWeekend($night);
            $inventory['902001'][] = new InventoryNight($night, match ($key) {
                '2027-03-03' => 0,
                '2027-03-05' => -1,
                default => 4 - (Days::diff(Days::date(self::FIRST_NIGHT), $night) % 3),
            }, 1, 30, Restriction::None, $weekend ? 560.0 : 420.0);
            $inventory['902002'][] = new InventoryNight($night, 2, 2, 14, Restriction::None, $weekend ? 820.0 : 640.0);
        }

        return new InMemoryPmsConnector([
            new BookingRecord('B1', 'STD', Days::date('2027-03-05'), Days::date('2027-03-07'), BookingStatus::Confirmed, 'booking', 'Booking.com', 1120.0, new DateTimeImmutable('2027-01-10T10:00:00Z'), new DateTimeImmutable('2027-01-10T10:00:00Z'), null, '902001', 2),
            new BookingRecord('B2', 'STE', Days::date('2027-03-12'), Days::date('2027-03-14'), BookingStatus::Cancelled, 'direct', '', null, new DateTimeImmutable('2027-02-01T10:00:00Z'), new DateTimeImmutable('2027-02-03T10:00:00Z'), new DateTimeImmutable('2027-02-03T10:00:00Z'), '902002'),
        ], $inventory);
    }

    public static function pricing(): InMemoryPricingConnector
    {
        $pushed = new DateTimeImmutable('2027-03-01T11:00:00Z');
        $prices = [];
        foreach (self::nights() as $night) {
            $prices['L-STD'][] = new PriceNight($night, self::isWeekend($night) ? 560.0 : 420.0, 380.0, null, 1, 1, 4);
            $prices['L-STE'][] = new PriceNight($night, self::isWeekend($night) ? 820.0 : 640.0, 500.0, null, 2, 0, 2);
        }

        return new InMemoryPricingConnector(
            [
                new ListingInfo('L-STD', 'Standard', 320.0, 480.0, 1600.0, 'BRL', new SyncStatus(true, $pushed, $pushed)),
                new ListingInfo('L-STE', 'Suite', 450.0, 700.0, 2400.0, 'BRL', new SyncStatus(false, null, $pushed)),
            ],
            $prices,
            ['L-STD' => [new DateOverride(Days::date('2027-03-10'), -10.0, 50.0)]],
        );
    }

    /**
     * @return list<MarketAnchor>
     */
    public static function weekdayAnchors(): array
    {
        $anchors = [];
        foreach (self::nights() as $night) {
            if (!self::isWeekend($night)) {
                $anchors[] = new MarketAnchor($night, 400.0, 480.0, 560.0, 650.0, 800.0, 8, Days::date('2027-02-27'), AnchorOrigin::Measured);
            }
        }

        return $anchors;
    }

    /**
     * The five stages of the shadow run, with the connectors of this world unless others are given.
     *
     * @return list<Stage>
     */
    public function stages(?ConnectorSource $connectors = null): array
    {
        return [
            new CollectStage($connectors ?? new StaticConnectorSource($this->pms, $this->pricing), $this->snapshots, $this->bookings, $this->clock),
            new MeasureStage($this->snapshots, $this->bookings, $this->alerts, $this->clock),
            new DecideStage(new RunInputsReader($this->snapshots, $this->anchors, new StepLedgerRepository($this->db), new OrderRepository($this->db, $this->clock)), $this->clock, 'beds24'),
            new ValidateStage(),
            new ShadowStage($this->planItems, new RunPayloadRepository($this->db, $this->clock)),
        ];
    }

    /**
     * @param list<Stage>|null $stages
     */
    public function service(?ConnectorSource $connectors = null, ?array $stages = null): ShadowRunService
    {
        return new ShadowRunService(
            new PropertyRepository($this->db, $this->clock),
            new RunOpener(new RoomTypeRepository($this->db, $this->clock), new RuleSetRepository($this->db, $this->clock), $this->runs, $this->clock, $this->logger),
            $this->runs,
            $this->locks,
            $this->switch,
            new RunPipeline($stages ?? $this->stages($connectors), $this->runs, $this->db, $this->switch, $this->clock, $this->logger),
            $this->clock,
            $this->logger,
        );
    }
}
