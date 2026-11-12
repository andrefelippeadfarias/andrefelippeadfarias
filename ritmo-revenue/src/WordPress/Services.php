<?php

declare(strict_types=1);

namespace Ritmo\WordPress;

use Closure;
use Ritmo\Application\Dashboard\OccupancyQuery;
use Ritmo\Application\Dashboard\PropertyDashboard;
use Ritmo\Application\EmergencySwitch;
use Ritmo\Application\Setup\SetupService;
use Ritmo\Application\ShadowRun\CollectStage;
use Ritmo\Application\ShadowRun\DecideStage;
use Ritmo\Application\ShadowRun\MeasureStage;
use Ritmo\Application\ShadowRun\RunInputsReader;
use Ritmo\Application\ShadowRun\RunPipeline;
use Ritmo\Application\ShadowRun\ShadowRunService;
use Ritmo\Application\ShadowRun\ShadowStage;
use Ritmo\Application\ShadowRun\ValidateStage;
use Ritmo\Domain\Support\Clock;
use Ritmo\Domain\Support\SystemClock;
use Ritmo\Infrastructure\Db\Migration\Migrator;
use Ritmo\Infrastructure\Db\Repository\AlertRepository;
use Ritmo\Infrastructure\Db\Repository\BookingRepository;
use Ritmo\Infrastructure\Db\Repository\InventorySnapshotRepository;
use Ritmo\Infrastructure\Db\Repository\MarketAnchorRepository;
use Ritmo\Infrastructure\Db\Repository\OrderRepository;
use Ritmo\Infrastructure\Db\Repository\PlanItemRepository;
use Ritmo\Infrastructure\Db\Repository\PriceSampleRepository;
use Ritmo\Infrastructure\Db\Repository\PropertyRepository;
use Ritmo\Infrastructure\Db\Repository\QuotaLogRepository;
use Ritmo\Infrastructure\Db\Repository\RoomTypeRepository;
use Ritmo\Infrastructure\Db\Repository\RuleSetRepository;
use Ritmo\Infrastructure\Db\Repository\RunPayloadRepository;
use Ritmo\Infrastructure\Db\Repository\RunRepository;
use Ritmo\Infrastructure\Db\Repository\StepLedgerRepository;
use Ritmo\Infrastructure\Db\WpdbDatabase;
use Ritmo\Infrastructure\Http\WpHttpClient;
use Ritmo\Infrastructure\Lock\DbLock;
use Ritmo\Infrastructure\Log\DbLogger;
use Ritmo\Infrastructure\Log\LogSanitizer;
use Ritmo\Infrastructure\Secrets\SodiumVault;
use Ritmo\Infrastructure\Store\OptionsStore;

/**
 * The objects of one WordPress request, built on first use and shared. This is where the
 * modules are wired together; nothing else creates infrastructure or connectors.
 */
final class Services
{
    private static ?self $instance = null;

    /** @var array<string, object> */
    private array $made = [];

    private function __construct(private readonly WpdbDatabase $db)
    {
    }

    public static function instance(): self
    {
        global $wpdb;

        return self::$instance ??= new self(new WpdbDatabase($wpdb));
    }

    public function db(): WpdbDatabase
    {
        return $this->db;
    }

    public function clock(): Clock
    {
        return $this->once('clock', static fn (): Clock => new SystemClock());
    }

    public function store(): OptionsStore
    {
        return $this->once('store', static fn (): OptionsStore => new OptionsStore());
    }

    public function sanitizer(): LogSanitizer
    {
        return $this->once('sanitizer', static fn (): LogSanitizer => new LogSanitizer());
    }

    public function logger(): DbLogger
    {
        return $this->once('logger', fn (): DbLogger => new DbLogger($this->db, $this->clock(), $this->sanitizer()));
    }

    public function vault(): SodiumVault
    {
        return $this->once('vault', fn (): SodiumVault => SodiumVault::forSite($this->store(), $this->clock()));
    }

    public function migrator(): Migrator
    {
        return $this->once('migrator', fn (): Migrator => Migrator::standard(
            $this->db,
            $this->store(),
            static function (string $sql): void {
                require_once ABSPATH . 'wp-admin/includes/upgrade.php';
                dbDelta($sql);
            },
            $this->db->charsetCollate()
        ));
    }

    public function properties(): PropertyRepository
    {
        return $this->once('properties', fn (): PropertyRepository => new PropertyRepository($this->db, $this->clock()));
    }

    public function roomTypes(): RoomTypeRepository
    {
        return $this->once('roomTypes', fn (): RoomTypeRepository => new RoomTypeRepository($this->db, $this->clock()));
    }

    public function ruleSets(): RuleSetRepository
    {
        return $this->once('ruleSets', fn (): RuleSetRepository => new RuleSetRepository($this->db, $this->clock()));
    }

    public function runs(): RunRepository
    {
        return $this->once('runs', fn (): RunRepository => new RunRepository($this->db));
    }

    public function runPayloads(): RunPayloadRepository
    {
        return $this->once('runPayloads', fn (): RunPayloadRepository => new RunPayloadRepository($this->db, $this->clock()));
    }

    public function planItems(): PlanItemRepository
    {
        return $this->once('planItems', fn (): PlanItemRepository => new PlanItemRepository($this->db, $this->clock()));
    }

    public function alerts(): AlertRepository
    {
        return $this->once('alerts', fn (): AlertRepository => new AlertRepository($this->db, $this->clock()));
    }

    public function snapshots(): InventorySnapshotRepository
    {
        return $this->once('snapshots', fn (): InventorySnapshotRepository => new InventorySnapshotRepository($this->db));
    }

    public function locks(): DbLock
    {
        return $this->once('locks', fn (): DbLock => new DbLock($this->db, $this->clock()));
    }

    public function emergencySwitch(): EmergencySwitch
    {
        return $this->once('switch', fn (): EmergencySwitch => new EmergencySwitch($this->store(), EmergencySwitch::constantIsSet()));
    }

    public function connectors(): ConnectorFactory
    {
        return $this->once('connectors', fn (): ConnectorFactory => new ConnectorFactory(
            $this->vault(),
            $this->store(),
            $this->roomTypes(),
            new QuotaLogRepository($this->db),
            new WpHttpClient(),
            $this->clock(),
            $this->logger(),
            $this->sanitizer(),
        ));
    }

    public function shadowRuns(): ShadowRunService
    {
        return $this->once('shadowRuns', function (): ShadowRunService {
            $clock = $this->clock();
            $bookings = new BookingRepository($this->db);
            $anchors = new MarketAnchorRepository($this->db, $clock);
            $pipeline = new RunPipeline([
                new CollectStage($this->connectors(), $this->snapshots(), $bookings, $clock),
                new MeasureStage($this->snapshots(), $bookings, $this->alerts(), $clock),
                new DecideStage(
                    new RunInputsReader($this->snapshots(), $anchors, new StepLedgerRepository($this->db), new OrderRepository($this->db, $clock)),
                    $clock,
                    $this->connectors()->pricingPmsName(),
                ),
                new ValidateStage(),
                new ShadowStage($this->planItems(), $this->runPayloads()),
            ], $this->runs(), $this->db, $this->emergencySwitch(), $clock, $this->logger());

            return new ShadowRunService($this->properties(), $this->roomTypes(), $this->ruleSets(), $this->runs(), $this->locks(), $this->emergencySwitch(), $pipeline, $clock, $this->logger());
        });
    }

    public function occupancy(): OccupancyQuery
    {
        return $this->once('occupancy', fn (): OccupancyQuery => new OccupancyQuery($this->properties(), $this->roomTypes(), $this->ruleSets(), $this->snapshots(), $this->clock()));
    }

    public function dashboard(): PropertyDashboard
    {
        return $this->once('dashboard', fn (): PropertyDashboard => new PropertyDashboard($this->properties(), $this->occupancy(), $this->runs(), $this->planItems(), $this->alerts()));
    }

    public function setup(): SetupService
    {
        return $this->once('setup', fn (): SetupService => new SetupService(
            $this->db,
            $this->properties(),
            $this->roomTypes(),
            $this->ruleSets(),
            new PriceSampleRepository($this->db),
            new MarketAnchorRepository($this->db, $this->clock()),
            $this->clock(),
        ));
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
