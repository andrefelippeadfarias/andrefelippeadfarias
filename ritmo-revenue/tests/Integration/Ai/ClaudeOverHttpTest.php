<?php

declare(strict_types=1);

namespace Ritmo\Tests\Integration\Ai;

use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use Ritmo\Application\Ai\AiAssistant;
use Ritmo\Application\Ai\AiSettingsStore;
use Ritmo\Application\Ai\RequestData;
use Ritmo\Application\Ai\UsageLedger;
use Ritmo\Connectors\Ai\Claude\AiHttpGuard;
use Ritmo\Connectors\Ai\Claude\ClaudeClient;
use Ritmo\Connectors\Ai\Claude\ClaudeConfig;
use Ritmo\Connectors\Ai\Claude\ClaudeProvider;
use Ritmo\Connectors\Support\BaseUrl;
use Ritmo\Contracts\Exception\WriteBlocked;
use Ritmo\Domain\Ai\AiDraft;
use Ritmo\Domain\Ai\AiGateway;
use Ritmo\Domain\Ai\AiPurpose;
use Ritmo\Domain\Ai\AiSettings;
use Ritmo\Domain\Ai\AiStopped;
use Ritmo\Domain\Config\RuleSet;
use Ritmo\Domain\Support\Days;
use Ritmo\Infrastructure\Http\CurlHttpClient;
use Ritmo\Infrastructure\Http\GuardedHttpClient;
use Ritmo\Infrastructure\Http\ReadRouteAllowList;
use Ritmo\Infrastructure\Http\RequestTarget;
use Ritmo\Infrastructure\Secrets\SodiumVault;
use Ritmo\Infrastructure\Store\ArrayStore;
use Ritmo\Tests\Fakes\MovableClock;
use Ritmo\Tests\Fakes\RecordingLogger;
use Ritmo\Tests\Unit\Application\Ai\AiWorld;
use Ritmo\Tests\Unit\Domain\Ai\AiFixtures;

/**
 * The assistant over real HTTP (cURL) to the simulator on 127.0.0.1, through the HTTP layer the
 * site uses: the plugin's read-only guard with the assistant's own allow-list (one POST route),
 * then the assistant's guard. Nothing leaves while it is off or has no key; the Claude host is on
 * no list of the price connectors and their hosts are on none of the assistant's. Pointing at the
 * simulator needs RITMO_DEV, a constant: every test runs in its own process.
 */
#[RunTestsInSeparateProcesses]
final class ClaudeOverHttpTest extends TestCase
{
    private const KEY = 'sk-ant-http-key-0001';

    private AnthropicServer $server;

    private RecordingLogger $logger;

    protected function setUp(): void
    {
        if (!defined(BaseUrl::DEV_CONSTANT)) {
            define(BaseUrl::DEV_CONSTANT, true);
        }
        $this->server = AnthropicServer::start();
        $this->logger = new RecordingLogger();
    }

    protected function tearDown(): void
    {
        $this->server->stop();
        self::assertStringNotContainsString(self::KEY, $this->logger->dump());
    }

    public function testNothingLeavesWithoutAKeyOrWhileTheAssistantIsOff(): void
    {
        [$assistant, $settings] = $this->assistant();
        foreach (['off without a key', 'on without a key', 'off with a key'] as $state) {
            if ($state === 'on without a key') {
                $settings->save(AiSettings::defaults()->with(['enabled' => true]));
            }
            if ($state === 'off with a key') {
                $settings->save(AiSettings::defaults());
                $settings->putKey(self::KEY);
            }
            foreach ([
                static fn () => $assistant->reportText(AiPurpose::WeeklyReport, [], [['label' => 'Bookings', 'value' => '4']], 'en'),
                static fn () => $assistant->explainAlert(['summary' => 'x'], 'en'),
                static fn () => $assistant->testConnection(),
            ] as $call) {
                try {
                    $call();
                    self::fail($state);
                } catch (AiStopped $e) {
                    self::assertContains($e->reason, [AiStopped::DISABLED, AiStopped::NO_KEY], $state);
                }
            }
        }
        self::assertSame([], $this->server->log(), 'the simulator received nothing');
    }

    public function testAReportAndADraftGoOverHttpWithTheCacheAndTheUsage(): void
    {
        [$assistant, $settings, $usage] = $this->assistant();
        $settings->putKey(self::KEY);
        $settings->save(AiSettings::defaults()->with(['enabled' => true]));

        $models = $assistant->testConnection();
        $first = $assistant->reportText(AiPurpose::WeeklyReport, ['property' => 'inn-1'], [['label' => 'Bookings made', 'value' => '4']], 'pt_BR');
        $second = $assistant->reportText(AiPurpose::WeeklyReport, ['property' => 'inn-1'], [['label' => 'Bookings made', 'value' => '6']], 'pt_BR');
        $text = 'Não vender abaixo de R$ 900 no feriado';
        ['data' => $data, 'context' => $context] = RequestData::of(AiWorld::property(), AiWorld::rooms(), RuleSet::fromArray(AiFixtures::rules()), Days::date('2026-11-15'), $text);
        $draft = $assistant->draft($text, $data, $context, 'pt_BR');

        self::assertCount(3, $models);
        self::assertSame('Resumo da semana. Bookings made: 4.', $first->text);
        self::assertSame('Resumo da semana. Bookings made: 6.', $second->text);
        self::assertSame([AiDraft::ORDER, ['STD' => '900.00', 'SUITE' => '900.00']], [$draft->kind, $draft->order?->floors]);
        $log = $this->server->log();
        self::assertSame(['GET /v1/models', 'POST /v1/messages', 'POST /v1/messages', 'POST /v1/messages'], array_map(static fn (array $r): string => $r['method'] . ' ' . $r['path'], $log));
        self::assertSame([200, 200, 200, 200], array_column($log, 'status'));
        $month = $usage->current();
        self::assertSame(3, $month->totals['calls']);
        self::assertGreaterThan(0, $month->totals['cache_read'], 'the second report read the system prompt from the cache');
    }

    public function testAnOverloadIsRepeatedOverHttpAndARefusalIsAClearStop(): void
    {
        [$assistant, $settings] = $this->assistant();
        $settings->putKey(self::KEY);
        $settings->save(AiSettings::defaults()->with(['enabled' => true]));
        $this->server->script(['kind' => 'error', 'status' => 529, 'type' => 'overloaded_error'], ['kind' => 'refusal', 'category' => 'bio']);

        try {
            $assistant->reportText(AiPurpose::RunReport, [], [['label' => 'Written calls', 'value' => '2']], 'en');
            self::fail('refused');
        } catch (AiStopped $e) {
            self::assertSame([AiStopped::REFUSAL, ['category' => 'bio']], [$e->reason, $e->details]);
        }
        self::assertSame([529, 200], array_column($this->server->log(), 'status'));
    }

    public function testTheAssistantsAllowListAndThePriceConnectorsListsNeverMix(): void
    {
        $pricingRoutes = ReadRouteAllowList::none()->with('pricelabs', 'POST', '127.0.0.1:' . $this->server->port, '/pricelabs/v1/listing_prices');
        $pricingGuard = new GuardedHttpClient(new CurlHttpClient(), $pricingRoutes, $this->logger);
        try {
            $pricingGuard->request('POST', $this->server->baseUrl() . ClaudeConfig::MESSAGES_PATH, [], '{}');
            self::fail('the price connectors cannot reach the assistant route');
        } catch (WriteBlocked) {
        }
        $aiHttp = $this->aiHttp();
        foreach (['/pricelabs/v1/listing_prices', '/beds24/v2/inventory/rooms/calendar', '/v1/messages/batches'] as $path) {
            try {
                $aiHttp->request('POST', 'http://127.0.0.1:' . $this->server->port . $path, [], '{}');
                self::fail('the assistant cannot reach ' . $path);
            } catch (WriteBlocked) {
            }
        }
        self::assertSame([], $this->server->log());
    }

    /**
     * @return array{0: AiAssistant, 1: AiSettingsStore, 2: UsageLedger}
     */
    private function assistant(): array
    {
        $clock = new MovableClock('2026-11-15T13:00:00Z');
        $store = new ArrayStore();
        $settings = new AiSettingsStore($store, new SodiumVault(new ArrayStore(), $clock, str_repeat('k', 40)));
        $usage = new UsageLedger($store, $clock, 'America/Sao_Paulo');
        $assistant = new AiAssistant($settings, $usage, fn (AiSettings $s, string $key): AiGateway => new ClaudeProvider(new ClaudeClient($this->aiHttp(), ClaudeConfig::fromSettings($s, $key, $this->server->baseUrl()), $this->logger, static function (): void {
        })), $this->logger);

        return [$assistant, $settings, $usage];
    }

    private function aiHttp(): AiHttpGuard
    {
        $messages = RequestTarget::fromUrl($this->server->baseUrl() . ClaudeConfig::MESSAGES_PATH);

        return new AiHttpGuard(new GuardedHttpClient(new CurlHttpClient(), ReadRouteAllowList::none()->with(ClaudeProvider::ID, 'POST', $messages->host, $messages->path), $this->logger), $this->server->baseUrl());
    }
}
