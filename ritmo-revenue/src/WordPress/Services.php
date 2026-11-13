<?php

declare(strict_types=1);

namespace Ritmo\WordPress;

use Closure;
use Ritmo\Application\Dashboard\OccupancyQuery;
use Ritmo\Application\Dashboard\PropertyDashboard;
use Ritmo\Application\EmergencySwitch;
use Ritmo\Application\Run\RunStack;
use Ritmo\Application\Setup\SetupService;
use Ritmo\Application\ShadowRun\ShadowRunService;
use Ritmo\Application\Write\Gate\WriteGate;
use Ritmo\Domain\Config\RuleSet;
use Ritmo\Domain\Support\Clock;
use Ritmo\Domain\Support\SystemClock;
use Ritmo\Infrastructure\Db\Migration\Migrator;
use Ritmo\Infrastructure\Db\Repository\AlertRepository;
use Ritmo\Infrastructure\Db\Repository\InventorySnapshotRepository;
use Ritmo\Infrastructure\Db\Repository\MarketAnchorRepository;
use Ritmo\Infrastructure\Db\Repository\PlanItemRepository;
use Ritmo\Infrastructure\Db\Repository\PriceSampleRepository;
use Ritmo\Infrastructure\Db\Repository\PropertyRepository;
use Ritmo\Infrastructure\Db\Repository\QuotaLogRepository;
use Ritmo\Infrastructure\Db\Repository\RoomTypeRepository;
use Ritmo\Infrastructure\Db\Repository\RuleSetRepository;
use Ritmo\Infrastructure\Db\Repository\RunRepository;
use Ritmo\Infrastructure\Db\WpdbDatabase;
use Ritmo\Infrastructure\Http\WpHttpClient;
use Ritmo\Infrastructure\Lock\DbLock;
use Ritmo\Infrastructure\Log\DbLogger;
use Ritmo\Infrastructure\Log\LogSanitizer;
use Ritmo\Infrastructure\Schedule\WpCronTrigger;
use Ritmo\Infrastructure\Schedule\WpMailer;
use Ritmo\Infrastructure\Schedule\WpRecipientDirectory;
use Ritmo\Infrastructure\Secrets\SodiumVault;
use Ritmo\Infrastructure\Store\OptionsStore;
use Ritmo\Infrastructure\Support\SystemSleeper;

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
        return $this->stack()->properties();
    }

    public function roomTypes(): RoomTypeRepository
    {
        return $this->stack()->roomTypes();
    }

    public function ruleSets(): RuleSetRepository
    {
        return $this->stack()->ruleSets();
    }

    public function runs(): RunRepository
    {
        return $this->stack()->runs();
    }

    public function planItems(): PlanItemRepository
    {
        return $this->stack()->planItems();
    }

    public function alerts(): AlertRepository
    {
        return $this->once('alerts', fn (): AlertRepository => new AlertRepository($this->db, $this->clock()));
    }

    public function snapshots(): InventorySnapshotRepository
    {
        return $this->stack()->snapshots();
    }

    public function locks(): DbLock
    {
        return $this->stack()->locks();
    }

    public function emergencySwitch(): EmergencySwitch
    {
        return $this->once('switch', fn (): EmergencySwitch => new EmergencySwitch($this->store(), EmergencySwitch::constantIsSet()));
    }

    /**
     * The write gate: the only issuer of write tokens; the connectors check every write with it.
     */
    public function gate(): WriteGate
    {
        return $this->once('gate', function (): WriteGate {
            $rules = (new RuleSetRepository($this->db, $this->clock()))->current() ?? RuleSet::defaults();

            return new WriteGate($this->clock(), $this->emergencySwitch(), $rules->write(), $rules->plan());
        });
    }

    public function connectors(): ConnectorFactory
    {
        return $this->once('connectors', fn (): ConnectorFactory => new ConnectorFactory(
            $this->vault(),
            $this->store(),
            new RoomTypeRepository($this->db, $this->clock()),
            new QuotaLogRepository($this->db),
            new WpHttpClient(),
            $this->clock(),
            $this->logger(),
            $this->sanitizer(),
            $this->gate(),
        ));
    }

    /**
     * Every run, the scheduler and the services around them (approvals, modes, undo).
     */
    public function stack(): RunStack
    {
        return $this->once('stack', fn (): RunStack => new RunStack(
            $this->db,
            $this->clock(),
            $this->store(),
            $this->logger(),
            $this->connectors(),
            $this->emergencySwitch(),
            $this->gate(),
            new WpCapabilityCheck(),
            new WpMailer(),
            new WpRecipientDirectory($this->store()),
            new SystemSleeper(),
            static fn () => WpCronTrigger::spawnNow(),
        ));
    }

    public function shadowRuns(): ShadowRunService
    {
        return $this->stack()->shadowRuns();
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
