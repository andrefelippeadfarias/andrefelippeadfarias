<?php

declare(strict_types=1);

namespace Ritmo\Tests\Integration\Run\Support;

use Ritmo\Application\Approval\CapabilityCheck;
use Ritmo\Application\EmergencySwitch;
use Ritmo\Application\Dashboard\Setup\SafetyValues;
use Ritmo\Application\Mode\ModePreconditions;
use Ritmo\Application\Mode\ModeService;
use Ritmo\Application\Run\RunStack;
use Ritmo\Application\Schedule\LaunchResult;
use Ritmo\Application\Write\Gate\WriteGate;
use Ritmo\Connectors\Beds24\Beds24Config;
use Ritmo\Connectors\Beds24\Beds24Connector;
use Ritmo\Connectors\Beds24\Beds24Credentials;
use Ritmo\Connectors\PriceLabs\PriceLabsConfig;
use Ritmo\Connectors\PriceLabs\PriceLabsConnector;
use Ritmo\Domain\Config\RuleSet;
use Ritmo\Domain\Model\AnchorOrigin;
use Ritmo\Domain\Model\MarketAnchor;
use Ritmo\Domain\Model\Property;
use Ritmo\Domain\Model\PropertyMode;
use Ritmo\Domain\Operations\RunKind;
use Ritmo\Domain\Support\DateRange;
use Ritmo\Domain\Support\Days;
use Ritmo\Infrastructure\Db\Migration\Migrations;
use Ritmo\Infrastructure\Db\Migration\Migrator;
use Ritmo\Infrastructure\Db\PdoDatabase;
use Ritmo\Infrastructure\Db\Repository\MarketAnchorRepository;
use Ritmo\Infrastructure\Db\Repository\OperationEventRepository;
use Ritmo\Infrastructure\Http\GuardedHttpClient;
use Ritmo\Infrastructure\Schedule\RecipientDirectory;
use Ritmo\Infrastructure\Store\ArrayStore;
use Ritmo\Tests\Fakes\ClockSleeper;
use Ritmo\Tests\Fakes\MemoryQuotaLedger;
use Ritmo\Tests\Fakes\MovableClock;
use Ritmo\Tests\Fakes\RecordingLogger;
use Ritmo\Tests\Fakes\StaticConnectorSource;
use Ritmo\Tests\Integration\Write\Support\InProcessApis;
use Ritmo\Tests\Integration\Write\Support\SimWorld;
use Ritmo\Tests\Unit\Application\Alerts\RecordingMailer;
use Ritmo\Tests\Unit\Application\Verify\Capabilities;

/**
 * The whole plugin minus WordPress, run against the simulator's invented property: a migrated
 * SQLite database, the real connectors behind the real HTTP guard (read routes, write routes, the
 * write gate as authority) over the in-process simulator with writes enabled, and the run stack
 * the WordPress composition root builds. "Now" is Wednesday 3 March 2027 at noon UTC; the
 * property's timezone is UTC. Rooms: STD (Beds24 902001, 4 units) and GDN (902002, 2 units),
 * with market anchors on every night so the engine has something to decide.
 */
final class RunWorld
{
    public const PROPERTY = 'inn';

    /** The person who approves, manages the rules and the connections. */
    public const OWNER = 1;

    public readonly MovableClock $clock;

    public readonly InProcessApis $apis;

    public readonly HookedApis $hook;

    public readonly ArrayStore $store;

    public readonly EmergencySwitch $switch;

    public readonly WriteGate $gate;

    public readonly PdoDatabase $db;

    public readonly RecordingLogger $logger;

    public readonly RecordingMailer $mailer;

    public readonly Capabilities $capabilities;

    public readonly RunStack $stack;

    /**
     * @param array<string, mixed> $rules sections of the rule set over the neutral defaults
     */
    public function __construct(PropertyMode $mode = PropertyMode::Shadow, array $rules = [])
    {
        $this->clock = new MovableClock(SimWorld::NOW);
        $this->apis = new InProcessApis();
        $this->apis->enableWrites();
        $this->store = new ArrayStore();
        $this->switch = new EmergencySwitch($this->store);
        $this->db = PdoDatabase::inMemory();
        (new Migrator($this->db, new ArrayStore(), Migrations::all()))->migrate();
        $this->logger = new RecordingLogger();
        $this->mailer = new RecordingMailer();
        $this->capabilities = new Capabilities([self::OWNER => [CapabilityCheck::APPROVE, ModeService::MANAGE_RULES, ModeService::MANAGE_CONNECTIONS]]);
        $ruleSet = RuleSet::fromArray(array_replace_recursive(RuleSet::defaults()->toArray(), $rules));
        $this->gate = new WriteGate($this->clock, $this->switch, $ruleSet->write(), $ruleSet->plan());
        $this->hook = new HookedApis($this->apis);
        $http = new GuardedHttpClient($this->hook, SimWorld::readRoutes(), $this->logger, SimWorld::writeRoutes(), $this->gate);
        $pricing = new PriceLabsConnector($http, new PriceLabsConfig('sim-pricelabs-key-0001', 'beds24'), $this->clock, new MemoryQuotaLedger(), $this->logger, $this->gate);
        $pms = new Beds24Connector($http, new Beds24Config(Beds24Credentials::longLifeToken('sim-long-life-token-0001'), ['902001' => 'STD', '902002' => 'GDN'], [901001], null, 5.0, ['902001' => 4, '902002' => 2]), $this->clock, $this->logger, $this->gate);
        $recipients = new class () implements RecipientDirectory {
            public function addresses(): array
            {
                return ['owner@example.test'];
            }
        };
        $this->stack = new RunStack($this->db, $this->clock, $this->store, $this->logger, new StaticConnectorSource($pms, $pricing, true), $this->switch, $this->gate, $this->capabilities, $this->mailer, $recipients, new ClockSleeper($this->clock));
        $this->stack->ruleSets()->save($ruleSet);
        $this->seed($mode);
    }

    /**
     * Moves the property to a mode the way a person does, through the steps the mode service
     * records (the autonomous authorization reads them).
     */
    public function switchTo(PropertyMode $mode): void
    {
        $events = new OperationEventRepository($this->db);
        $from = $this->property()->mode;
        foreach ($mode === PropertyMode::Auto && $from === PropertyMode::Shadow ? [PropertyMode::Approval, PropertyMode::Auto] : [$mode] as $to) {
            $events->record(self::PROPERTY, OperationEventRepository::MODE, $this->property()->mode->value, $to->value, 'test', 'user:' . self::OWNER, $this->clock->now());
            $this->stack->properties()->setMode(self::PROPERTY, $to);
        }
    }

    public function property(): Property
    {
        return $this->stack->properties()->find(self::PROPERTY) ?? throw new \LogicException('The property was not seeded.');
    }

    /**
     * A run of a kind through the launcher (the property's run lock), worked until it ends.
     */
    public function run(RunKind $kind = RunKind::Full, int $budget = 300): LaunchResult
    {
        $result = $this->stack->launcher()->start($this->property(), $kind, $this->switch->isEngaged(), $budget);
        for ($i = 0; $i < 20 && $result->status->value === 'running'; ++$i) {
            $result = $this->stack->launcher()->continueOpen($this->property(), $budget) ?? $result;
        }

        return $result;
    }

    /**
     * @return array<string, mixed> the stored summary of a run
     */
    public function summary(?int $runId): array
    {
        return $runId === null ? [] : ($this->stack->runs()->find($runId)['summary'] ?? []);
    }

    /**
     * "METHOD /path" of every write request that reached the simulator (the calendar read excluded).
     *
     * @return list<string>
     */
    public function writes(): array
    {
        return $this->apis->writeLines();
    }

    public function later(string $modify): void
    {
        $target = $this->clock->now()->modify($modify);
        $this->clock->advance((float) ($target->getTimestamp() - $this->clock->now()->getTimestamp()));
    }

    /**
     * The owner tested both connections (a condition of the approval mode).
     */
    public function testConnections(bool $ok = true): void
    {
        foreach (ModePreconditions::PROVIDERS as $provider) {
            $this->store->set(ModePreconditions::CONNECTION_TEST_PREFIX . $provider, ['ok' => $ok, 'message' => $ok ? 'OK' : 'refused', 'at' => $this->clock->now()->format(DATE_ATOM)]);
        }
    }

    /**
     * A person allowed to change safety limits confirmed the safety values in force (step 7 of
     * the setup: a condition of the approval mode, void once a value changes).
     */
    public function confirmSafety(): void
    {
        $this->store->set(ModePreconditions::SETUP_KEY, [
            'safety_confirmed_at' => $this->clock->now()->format(DATE_ATOM),
            'safety_confirmed_by' => 1,
            'safety_fingerprint' => SafetyValues::fingerprint($this->stack->rules(), $this->stack->roomTypes()->all()),
        ]);
    }

    private function seed(PropertyMode $mode): void
    {
        $this->testConnections();
        $this->stack->properties()->save(new Property(self::PROPERTY, 'Example Inn', 'UTC', 'BRL'));
        foreach (SimWorld::rooms() as $room) {
            $this->stack->roomTypes()->save($room);
        }
        $this->confirmSafety();
        $anchors = new MarketAnchorRepository($this->db, $this->clock);
        foreach (DateRange::nights(Days::date('2027-02-28'), 70) as $night) {
            $anchors->save(self::PROPERTY, new MarketAnchor($night, 420.0, 500.0, 600.0, 700.0, 900.0, 8, Days::date('2027-02-27'), AnchorOrigin::Measured));
        }
        if ($mode !== PropertyMode::Shadow) {
            $this->switchTo($mode);
        }
    }
}
