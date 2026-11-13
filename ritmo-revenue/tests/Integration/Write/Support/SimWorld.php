<?php

declare(strict_types=1);

namespace Ritmo\Tests\Integration\Write\Support;

use DateTimeImmutable;
use PHPUnit\Framework\Assert;
use Ritmo\Application\EmergencySwitch;
use Ritmo\Application\Write\Execute\WriteExecutor;
use Ritmo\Application\Write\Execute\WriteOutcome;
use Ritmo\Application\Write\Execute\WriteRun;
use Ritmo\Application\Write\Gate\AutonomousAuthorization;
use Ritmo\Application\Write\Gate\IssuedToken;
use Ritmo\Application\Write\Gate\TonightState;
use Ritmo\Application\Write\Gate\WriteContext;
use Ritmo\Application\Write\Gate\WriteGate;
use Ritmo\Application\Write\Journal\StateReader;
use Ritmo\Application\Write\Journal\WriteJournal;
use Ritmo\Application\Write\Quota\RecalcGuard;
use Ritmo\Application\Write\Resume\ResumeReport;
use Ritmo\Application\Write\Resume\ResumeService;
use Ritmo\Application\Write\Schedule\CalendarChange;
use Ritmo\Application\Write\WriteConfig;
use Ritmo\Connectors\Beds24\Beds24Config;
use Ritmo\Connectors\Beds24\Beds24Connector;
use Ritmo\Connectors\Beds24\Beds24Credentials;
use Ritmo\Connectors\PriceLabs\PriceLabsConfig;
use Ritmo\Connectors\PriceLabs\PriceLabsConnector;
use Ritmo\Domain\Model\Property;
use Ritmo\Domain\Model\PropertyMode;
use Ritmo\Domain\Model\RoomType;
use Ritmo\Domain\Plan\NightState;
use Ritmo\Domain\Plan\Plan;
use Ritmo\Domain\Plan\PlanDeletion;
use Ritmo\Domain\Plan\PlanItem;
use Ritmo\Domain\Plan\ValidatedPlan;
use Ritmo\Domain\Plan\ValidationContext;
use Ritmo\Domain\Plan\Validator;
use Ritmo\Domain\Support\Days;
use Ritmo\Infrastructure\Db\Migration\DraftWrite;
use Ritmo\Infrastructure\Db\Migration\Migrations;
use Ritmo\Infrastructure\Db\Migration\Migrator;
use Ritmo\Infrastructure\Db\PdoDatabase;
use Ritmo\Infrastructure\Db\Repository\RecalcAttemptRepository;
use Ritmo\Infrastructure\Db\Repository\WriteOpRepository;
use Ritmo\Infrastructure\Http\GuardedHttpClient;
use Ritmo\Infrastructure\Http\ReadRouteAllowList;
use Ritmo\Infrastructure\Http\WriteRouteAllowList;
use Ritmo\Infrastructure\Store\ArrayStore;
use Ritmo\Tests\Fakes\MemoryQuotaLedger;
use Ritmo\Tests\Fakes\MovableClock;
use Ritmo\Tests\Fakes\RecordingLogger;
use Ritmo\Tests\Unit\Domain\EngineSample;

/**
 * The simulator's invented property (tests/fixtures/api/scenario.json) wired for a write run the
 * way the plugin will be: the real connectors behind the real HTTP guard (read routes, write
 * routes, the gate as write authority), the gate, the journal in a migrated SQLite database, the
 * recalculation quota and the executor. "Now" is Wednesday 3 March 2027 at noon UTC.
 * Rooms: STD (Beds24 902001, 4 units, base 480) and GDN (902002, 2 units, base 700).
 */
final class SimWorld
{
    public const NOW = '2027-03-03T12:00:00Z';
    public const STD = '901001___902001';
    public const GDN = '901001___902002';

    public readonly MovableClock $clock;
    public readonly InProcessApis $apis;
    public readonly EmergencySwitch $switch;
    public readonly WriteGate $gate;
    public readonly PdoDatabase $db;
    public readonly WriteOpRepository $ops;
    public readonly RecordingLogger $logger;
    public readonly GuardedHttpClient $http;
    public readonly PriceLabsConnector $pricing;
    public readonly Beds24Connector $pms;
    public readonly WriteConfig $config;
    private int $runs = 0;

    /**
     * @param list<string> $notReady listings the pricing system has not priced yet
     */
    public function __construct(?WriteConfig $config = null, array $notReady = [], string $now = self::NOW)
    {
        $this->config = $config ?? new WriteConfig();
        $this->clock = new MovableClock($now);
        $this->apis = new InProcessApis();
        $this->apis->enableWrites($notReady);
        $this->switch = new EmergencySwitch(new ArrayStore());
        $this->gate = new WriteGate($this->clock, $this->switch, $this->config);
        $this->db = PdoDatabase::inMemory();
        (new Migrator($this->db, new ArrayStore(), [...Migrations::all(), new DraftWrite()]))->migrate();
        $this->ops = new WriteOpRepository($this->db);
        $this->logger = new RecordingLogger();
        $this->http = new GuardedHttpClient($this->apis, self::readRoutes(), $this->logger, self::writeRoutes(), $this->gate);
        $this->pricing = new PriceLabsConnector($this->http, new PriceLabsConfig('sim-pricelabs-key-0001', 'beds24'), $this->clock, new MemoryQuotaLedger(), $this->logger, $this->gate);
        $this->pms = new Beds24Connector($this->http, new Beds24Config(Beds24Credentials::longLifeToken('sim-long-life-token-0001'), ['902001' => 'STD', '902002' => 'GDN'], [901001], null, 5.0, ['902001' => 4, '902002' => 2]), $this->clock, $this->logger, $this->gate);
    }

    public static function readRoutes(): ReadRouteAllowList
    {
        return ReadRouteAllowList::none()->with(PriceLabsConnector::ID, 'POST', 'api.pricelabs.co', '/v1' . PriceLabsConnector::PRICES_PATH);
    }

    public static function writeRoutes(string $pricingBase = PriceLabsConnector::BASE_URL, string $pmsBase = Beds24Connector::BASE_URL): WriteRouteAllowList
    {
        $routes = WriteRouteAllowList::none();
        foreach ([...PriceLabsConnector::WRITE_ROUTES, PriceLabsConnector::LISTING_ROUTE] as [$method, $path]) {
            $routes = $routes->withBase(PriceLabsConnector::ID, $method, $pricingBase, $path);
        }

        return $routes->withBase(Beds24Connector::ID, 'POST', $pmsBase, Beds24Connector::CALENDAR_PATH);
    }

    public static function property(PropertyMode $mode = PropertyMode::Auto): Property
    {
        return new Property('inn', 'Example Inn', 'UTC', 'BRL', $mode);
    }

    /**
     * @return list<RoomType>
     */
    public static function rooms(): array
    {
        return [
            new RoomType('inn', 'STD', 'Standard Room', 4, '902001', self::STD, 480.0, 320.0, 1600.0, 320.0),
            new RoomType('inn', 'GDN', 'Garden Suite', 2, '902002', self::GDN, 700.0, 450.0, 2400.0, 450.0),
        ];
    }

    public static function item(string $room, string $night, ?float $floor = null, ?float $ceiling = null, ?int $stay = null): PlanItem
    {
        return new PlanItem($room, Days::date($night), $floor, $ceiling, $stay, 'pace_step', [], null, reasonText: 'pace step');
    }

    /**
     * An item as the engine makes it in a new run: with what is in force for the night read now,
     * so an item whose percentages are already stored has no effect and drops out of the payload.
     */
    public function freshItem(string $room, string $night, ?float $floor = null, ?float $ceiling = null): PlanItem
    {
        $listing = $room === 'STD' ? self::STD : self::GDN;
        $rule = $this->pricing->readOverrides($listing, Days::date($night), Days::date($night))[0] ?? null;

        return new PlanItem($room, Days::date($night), $floor, $ceiling, null, 'pace_step', [], NightState::from(null, $rule), reasonText: 'pace step');
    }

    /**
     * @param list<PlanItem>     $items
     * @param list<PlanDeletion> $deletions
     */
    public function validate(array $items, array $deletions = []): ValidatedPlan
    {
        $plan = new Plan('inn', $this->clock->today('UTC'), $this->clock->now(), $items, $deletions);
        $result = (new Validator(EngineSample::rules(), $this->clock))->validate($plan, self::property(), self::rooms(), new ValidationContext());
        Assert::assertTrue($result->isValid(), implode(', ', $result->errorCodes()));
        Assert::assertNotNull($result->plan);

        return $result->plan;
    }

    /**
     * The gate's context with tonight read from the simulator now.
     */
    public function context(PropertyMode $mode = PropertyMode::Auto, bool $lockHeld = true): WriteContext
    {
        $today = $this->clock->today('UTC');
        $tonight = [];
        foreach (self::rooms() as $room) {
            $price = $this->pricing->readPrices($room->pricingRef, $today, $today)[0] ?? null;
            $rule = $this->pricing->readOverrides($room->pricingRef, $today, $today)[0] ?? null;
            $tonight[$room->code] = new TonightState($room->code, $price?->price, $rule);
        }

        return new WriteContext(self::property($mode), self::rooms(), $this->clock->now(), $tonight, static fn (): bool => $lockHeld, 'beds24');
    }

    /**
     * @param list<CalendarChange> $pms
     * @param list<string>         $recalculate
     */
    public function issue(ValidatedPlan $plan, array $pms = [], array $recalculate = []): IssuedToken
    {
        return $this->gate->issue($plan, new AutonomousAuthorization('inn', 1, new DateTimeImmutable('2027-03-01T00:00:00Z')), $this->context(), $pms, $recalculate);
    }

    public function execute(IssuedToken $issued, ValidatedPlan $plan, ?callable $heartbeat = null, ?DateTimeImmutable $deadline = null): WriteOutcome
    {
        $run = new WriteRun(++$this->runs, self::property(), $plan, $issued, $this->pricing, $this->pms, $heartbeat === null ? static fn (): bool => true : $heartbeat(...), $deadline, $this->clock->now());

        return $this->executor()->execute($run);
    }

    /**
     * Issue and execute in one go.
     *
     * @param list<CalendarChange> $pms
     * @param list<string>         $recalculate
     */
    public function write(ValidatedPlan $plan, array $pms = [], array $recalculate = []): WriteOutcome
    {
        return $this->execute($this->issue($plan, $pms, $recalculate), $plan);
    }

    /**
     * The resume of a new run: what earlier runs left unsettled, read again and classified.
     */
    public function resume(): ResumeReport
    {
        return (new ResumeService($this->ops))->resume(++$this->runs, 'inn', new StateReader($this->pricing, $this->pms));
    }

    public function executor(): WriteExecutor
    {
        return new WriteExecutor($this->gate, $this->switch, $this->clock, new WriteJournal($this->ops, $this->clock), $this->config, new RecalcGuard(new RecalcAttemptRepository($this->db), $this->clock, PriceLabsConnector::ID));
    }

    /**
     * @return array<string, array<string, mixed>> date => rule stored now for the listing
     */
    public function stored(string $listing): array
    {
        $rules = [];
        foreach ($this->apis->world()['overrides'][$listing] ?? [] as $date => $rule) {
            $rules[$date] = $rule;
        }

        return $rules;
    }
}
