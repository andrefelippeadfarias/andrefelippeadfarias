<?php

declare(strict_types=1);

namespace Ritmo\Tests\Unit\Application\Ai;

use Ritmo\Application\Ai\AiAssistant;
use Ritmo\Application\Ai\AiDraftQueue;
use Ritmo\Application\Ai\AiSettingsStore;
use Ritmo\Application\Ai\UsageLedger;
use Ritmo\Connectors\Ai\Claude\AiHttpGuard;
use Ritmo\Connectors\Ai\Claude\ClaudeClient;
use Ritmo\Connectors\Ai\Claude\ClaudeConfig;
use Ritmo\Connectors\Ai\Claude\ClaudeProvider;
use Ritmo\Domain\Ai\AiGateway;
use Ritmo\Domain\Ai\AiSettings;
use Ritmo\Domain\Model\Property;
use Ritmo\Domain\Model\RoomType;
use Ritmo\Infrastructure\Secrets\SodiumVault;
use Ritmo\Infrastructure\Store\ArrayStore;
use Ritmo\Tests\Fakes\MovableClock;
use Ritmo\Tests\Fakes\RecordingLogger;
use Ritmo\Tests\Unit\Connectors\Ai\SimulatedAnthropic;

/**
 * The assistant of one site wired to the simulator: an options store, a sodium vault, the usage
 * ledger, the queue of drafts, and a gateway factory that counts how often it was asked to build
 * the connector (it is never built when the assistant may not be asked).
 */
final class AiWorld
{
    public const KEY = 'sk-ant-world-key-0001';

    public readonly ArrayStore $store;
    public readonly MovableClock $clock;
    public readonly SodiumVault $vault;
    public readonly AiSettingsStore $settings;
    public readonly UsageLedger $usage;
    public readonly AiDraftQueue $drafts;
    public readonly RecordingLogger $logger;
    public readonly SimulatedAnthropic $api;
    public readonly AiAssistant $assistant;
    public int $gatewaysBuilt = 0;

    /** What the licence says about the assistant (LicenceVerdict::allowsAi()). */
    public bool $licensed = true;

    public function __construct(string $now = '2026-11-15T13:00:00Z')
    {
        $this->store = new ArrayStore();
        $this->clock = new MovableClock($now);
        $this->vault = new SodiumVault(new ArrayStore(), $this->clock, str_repeat('k', 40));
        $this->settings = new AiSettingsStore($this->store, $this->vault);
        $this->usage = new UsageLedger($this->store, $this->clock, 'America/Sao_Paulo');
        $this->drafts = new AiDraftQueue($this->store, $this->clock);
        $this->logger = new RecordingLogger();
        $this->api = new SimulatedAnthropic();
        $this->assistant = new AiAssistant($this->settings, $this->usage, function (AiSettings $settings, string $key): AiGateway {
            ++$this->gatewaysBuilt;

            return new ClaudeProvider(new ClaudeClient(new AiHttpGuard($this->api), ClaudeConfig::fromSettings($settings, $key), $this->logger, static function (): void {
            }));
        }, $this->logger, null, fn (): bool => $this->licensed);
    }

    /**
     * The assistant on, with a key.
     *
     * @param array<string, mixed> $settings
     */
    public function ready(array $settings = [], string $key = self::KEY): self
    {
        $this->settings->putKey($key);
        $this->settings->save(AiSettings::defaults()->with(['enabled' => true] + $settings));

        return $this;
    }

    public static function property(): Property
    {
        return new Property('inn-1', 'Example Inn', 'America/Sao_Paulo', 'BRL');
    }

    /**
     * @return list<RoomType>
     */
    public static function rooms(): array
    {
        return [
            new RoomType('inn-1', 'STD', 'Standard', 4, '101', 'L1', 500.0, 300.0, 1200.0, 400.0),
            new RoomType('inn-1', 'SUITE', 'Garden suite', 2, '102', 'L2', 800.0, 500.0, 2000.0, 650.0),
        ];
    }
}
