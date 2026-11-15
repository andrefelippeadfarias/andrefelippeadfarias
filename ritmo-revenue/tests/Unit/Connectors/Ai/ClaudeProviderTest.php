<?php

declare(strict_types=1);

namespace Ritmo\Tests\Unit\Connectors\Ai;

use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\TestCase;
use Ritmo\Connectors\Ai\Claude\AiHttpGuard;
use Ritmo\Connectors\Ai\Claude\ClaudeClient;
use Ritmo\Connectors\Ai\Claude\ClaudeConfig;
use Ritmo\Connectors\Ai\Claude\ClaudeProvider;
use Ritmo\Connectors\Ai\Claude\MessageBody;
use Ritmo\Contracts\Exception\AuthFailed;
use Ritmo\Contracts\Exception\ConnectorException;
use Ritmo\Contracts\Exception\RateLimited;
use Ritmo\Contracts\Exception\WriteBlocked;
use Ritmo\Domain\Ai\AiEffort;
use Ritmo\Domain\Ai\AiPurpose;
use Ritmo\Domain\Ai\AiReply;
use Ritmo\Domain\Ai\AiRequest;
use Ritmo\Domain\Ai\OutputSchemas;
use Ritmo\Domain\Ai\PromptLibrary;
use Ritmo\Tests\Fakes\RecordingLogger;
use Ritmo\Tests\Fakes\ScriptedHttpClient;

/**
 * The Claude connector against the simulator: the request carries exactly what this API version
 * accepts (and the simulator refuses what the real API refuses), the answer is read block by block
 * (empty thinking skipped, refusal, cut answer, fallback, usage with cache), the errors are clear
 * and only 429, 500 and 529 are repeated, within the time left.
 */
final class ClaudeProviderTest extends TestCase
{
    private const KEY = 'sk-ant-test-key-0001';

    public function testTheRequestCarriesOnlyWhatThisApiVersionAccepts(): void
    {
        $api = new SimulatedAnthropic();
        $reply = self::provider($api)->send(self::report());

        self::assertTrue($reply->isComplete());
        $request = $api->log()[0];
        self::assertSame([200, 'POST', '/v1/messages', '2026-10-21', 'server-side-fallback-2026-07-01', true], [$request['status'], $request['method'], $request['path'], $request['version'], $request['beta'], $request['has_key']]);
        $body = $request['body'];
        self::assertSame([], array_diff(array_keys($body), MessageBody::KEYS), 'only whitelisted keys');
        foreach (['temperature', 'top_p', 'top_k', 'thinking', 'tool_choice', 'tools', 'stream'] as $refused) {
            self::assertArrayNotHasKey($refused, $body);
        }
        self::assertSame(['claude-opus-5-5', 16000, 'default', ['effort' => 'medium']], [$body['model'], $body['max_tokens'], $body['fallbacks'], $body['output_config']]);
        self::assertSame([['role' => 'user', 'content' => self::report()->user]], $body['messages'], 'one user message, never an assistant prefill');
        self::assertCount(2, $body['system']);
        self::assertArrayNotHasKey('cache_control', $body['system'][0]);
        self::assertSame(['type' => 'ephemeral'], $body['system'][1]['cache_control'], 'the last stable block is the cache breakpoint');
        self::assertSame(PromptLibrary::REPORT, $body['system'][1]['text']);
        self::assertSame(120, $api->sent()[0]['timeout']);
        self::assertSame(self::KEY, $api->sent()[0]['headers']['x-api-key']);
    }

    public function testWithoutTheFallbackNeitherTheBetaHeaderNorTheFieldIsSent(): void
    {
        $api = new SimulatedAnthropic();
        self::provider($api, new ClaudeConfig(self::KEY, 'claude-haiku-5-5', AiEffort::High, false, 60))->send(self::report());

        $request = $api->log()[0];
        self::assertNull($request['beta']);
        self::assertArrayNotHasKey('fallbacks', $request['body']);
        self::assertSame(['claude-haiku-5-5', ['effort' => 'high']], [$request['body']['model'], $request['body']['output_config']]);
        self::assertSame(60, $api->sent()[0]['timeout']);
    }

    public function testAStructuredAnswerSendsItsSchemaAndTheSimulatorAcceptsIt(): void
    {
        $api = new SimulatedAnthropic();
        $request = new AiRequest(AiPurpose::AlertExplanation, PromptLibrary::system(AiPurpose::AlertExplanation), "Locale: en. Write in English.\n<data>\n{\"alert\":{\"summary\":\"Two rooms closed\"}}\n</data>", OutputSchemas::alert());

        $reply = self::provider($api)->send($request);

        self::assertSame(['type' => 'json_schema', 'schema' => OutputSchemas::alert()], $api->log()[0]['body']['output_config']['format']);
        self::assertSame(['explanation' => 'What happened: Two rooms closed', 'action' => 'Open the Alerts screen and review the night.'], json_decode($reply->text, true));
    }

    public function testTheAnswerSkipsEmptyThinkingAndCountsTheCache(): void
    {
        $api = new SimulatedAnthropic();
        $provider = self::provider($api);

        $first = $provider->send(self::report());
        $second = $provider->send(self::report());

        self::assertStringStartsWith('Summary of the week.', $first->text);
        self::assertSame(['end_turn', 'claude-opus-5-5', false], [$first->stopReason, $first->model, $first->fallbackUsed]);
        self::assertGreaterThan(0, $first->usage->cacheWrite);
        self::assertSame(0, $first->usage->cacheRead);
        self::assertSame(0, $second->usage->cacheWrite);
        self::assertGreaterThan(0, $second->usage->cacheRead, 'the same system prompt is read from the cache');
    }

    public function testARefusalACutAnswerAndAFallbackAreReadAsSuch(): void
    {
        $api = (new SimulatedAnthropic())->script(
            ['kind' => 'refusal', 'category' => 'cyber', 'explanation' => 'Declined.'],
            ['kind' => 'max_tokens', 'text' => 'The week was'],
            ['kind' => 'fallback', 'text' => 'Bookings made: 4.'],
        );
        $provider = self::provider($api);

        $refusal = $provider->send(self::report());
        self::assertSame([AiReply::STOP_REFUSAL, 'cyber', 'Declined.', '', false], [$refusal->stopReason, $refusal->refusalCategory, $refusal->refusalExplanation, $refusal->text, $refusal->isComplete()]);
        $cut = $provider->send(self::report());
        self::assertSame([AiReply::STOP_MAX_TOKENS, false], [$cut->stopReason, $cut->isComplete()]);
        $fallback = $provider->send(self::report());
        self::assertSame([true, 'claude-opus-4-8', 'Bookings made: 4.', true], [$fallback->fallbackUsed, $fallback->model, $fallback->text, $fallback->isComplete()]);
    }

    public function testTheSimulatorRefusesWhatTheRealApiRefuses(): void
    {
        $api = new SimulatedAnthropic();
        $base = ['model' => 'claude-opus-5-5', 'max_tokens' => 100, 'messages' => [['role' => 'user', 'content' => 'Hi']]];
        $headers = ['x-api-key' => self::KEY, 'anthropic-version' => '2026-10-21', 'content-type' => 'application/json'];
        $cases = [
            'temperature' => $base + ['temperature' => 0.2],
            'disabled thinking' => $base + ['thinking' => ['type' => 'disabled']],
            'thinking budget' => $base + ['thinking' => ['type' => 'enabled', 'budget_tokens' => 2000]],
            'forced tool' => $base + ['tool_choice' => ['type' => 'any']],
            'prefill' => ['messages' => [['role' => 'user', 'content' => 'Hi'], ['role' => 'assistant', 'content' => '{']]] + $base,
            'fallback without beta' => $base + ['fallbacks' => 'default'],
            'schema with a range' => $base + ['output_config' => ['format' => ['type' => 'json_schema', 'schema' => ['type' => 'object', 'properties' => ['n' => ['type' => 'integer', 'minimum' => 1]], 'required' => ['n'], 'additionalProperties' => false]]]],
            'open object' => $base + ['output_config' => ['format' => ['type' => 'json_schema', 'schema' => ['type' => 'object', 'properties' => [], 'required' => []]]]],
        ];
        foreach ($cases as $name => $body) {
            $reply = $api->api->handle('POST', '/v1/messages', $headers, (string) json_encode($body));
            self::assertSame(400, $reply->status, $name);
            self::assertSame('invalid_request_error', json_decode($reply->body, true)['error']['type'], $name);
        }
        self::assertSame(400, $api->api->handle('POST', '/v1/messages', ['anthropic-version' => '2023-06-01'] + $headers, (string) json_encode($base))->status, 'old version');
        self::assertSame(200, $api->api->handle('POST', '/v1/messages', $headers, (string) json_encode($base))->status);
    }

    public function testErrorsThatAreNeverRepeatedSayWhatToFix(): void
    {
        $cases = [
            [401, 'authentication_error', AuthFailed::class, 'refused the API key'],
            [403, 'permission_error', AuthFailed::class, 'may not use'],
            [402, 'billing_error', ConnectorException::class, 'billing'],
            [404, 'not_found_error', ConnectorException::class, 'Check the model'],
            [413, 'request_too_large', ConnectorException::class, 'too large'],
            [400, 'invalid_request_error', ConnectorException::class, 'invalid'],
        ];
        foreach ($cases as [$status, $type, $class, $words]) {
            $api = (new SimulatedAnthropic())->script(['kind' => 'error', 'status' => $status, 'type' => $type]);
            $waits = [];
            try {
                self::provider($api, null, $waits)->send(self::report());
                self::fail('HTTP ' . $status . ' must throw');
            } catch (ConnectorException $e) {
                self::assertInstanceOf($class, $e);
                self::assertStringContainsString($words, $e->getMessage());
                self::assertStringContainsString($type, $e->getMessage());
                self::assertStringNotContainsString(self::KEY, $e->getMessage());
                self::assertSame($status, $e->httpStatus());
            }
            self::assertCount(1, $api->log(), 'HTTP ' . $status . ' is not repeated');
            self::assertSame([], $waits);
        }
    }

    public function testARateLimitWaitsWhatRetryAfterAsksWhenItIsShort(): void
    {
        $api = (new SimulatedAnthropic())->script(['kind' => 'error', 'status' => 429, 'type' => 'rate_limit_error', 'retry_after' => 3]);
        $waits = [];

        $reply = self::provider($api, null, $waits)->send(self::report());

        self::assertTrue($reply->isComplete());
        self::assertSame([3.0], $waits);
        self::assertSame([429, 200], array_column($api->log(), 'status'));
    }

    public function testALongRateLimitStopsAtOnceWithItsWait(): void
    {
        $api = (new SimulatedAnthropic())->script(['kind' => 'error', 'status' => 429, 'type' => 'rate_limit_error', 'retry_after' => 600]);
        $waits = [];
        try {
            self::provider($api, null, $waits)->send(self::report());
            self::fail('a long rate limit stops');
        } catch (RateLimited $e) {
            self::assertSame(600, $e->retryAfterSeconds());
        }
        self::assertSame([], $waits);
        self::assertCount(1, $api->log());
    }

    public function testAnOverloadIsRepeatedTwiceWithAGrowingWait(): void
    {
        $api = (new SimulatedAnthropic())->script(['kind' => 'error', 'status' => 529, 'type' => 'overloaded_error'], ['kind' => 'error', 'status' => 500, 'type' => 'api_error']);
        $waits = [];

        self::assertTrue(self::provider($api, null, $waits)->send(self::report())->isComplete());
        self::assertSame([2.0, 4.0], $waits);
        self::assertSame([529, 500, 200], array_column($api->log(), 'status'));

        $api = (new SimulatedAnthropic())->script(...array_fill(0, 3, ['kind' => 'error', 'status' => 529, 'type' => 'overloaded_error']));
        $waits = [];
        try {
            self::provider($api, null, $waits)->send(self::report());
            self::fail('three overloads end the request');
        } catch (ConnectorException $e) {
            self::assertSame(529, $e->httpStatus());
            self::assertStringContainsString('overloaded', $e->getMessage());
        }
        self::assertCount(3, $api->log(), 'at most two repetitions');
        self::assertSame([2.0, 4.0], $waits);
    }

    public function testNoRepetitionAndShorterWaitsWhenThePhpRequestIsRunningOut(): void
    {
        $api = (new SimulatedAnthropic())->script(['kind' => 'error', 'status' => 529, 'type' => 'overloaded_error']);
        $waits = [];
        $left = 16.0;
        try {
            self::provider($api, null, $waits, static function () use (&$left): float {
                return $left;
            })->send(self::report());
            self::fail('no time for a repetition');
        } catch (ConnectorException $e) {
            self::assertSame(529, $e->httpStatus());
        }
        self::assertSame([], $waits, '2 s of wait plus 10 s of answer do not fit in 16 s minus the margin');
        self::assertSame(11, $api->sent()[0]['timeout'], 'the timeout shrinks to the time left');

        $api = new SimulatedAnthropic();
        $this->expectException(ConnectorException::class);
        $this->expectExceptionMessage('too little time');
        try {
            self::provider($api, null, $waits, static fn (): float => 8.0)->send(self::report());
        } finally {
            self::assertSame([], $api->sent(), 'nothing is sent without time for an answer');
        }
    }

    public function testATransportFailureIsNotRepeated(): void
    {
        $http = (new ScriptedHttpClient())->queue(new ConnectorException('timed out', 'http', null, true));
        $waits = [];
        try {
            self::provider($http, null, $waits)->send(self::report());
            self::fail('a lost answer is not repeated');
        } catch (ConnectorException) {
        }
        self::assertCount(1, $http->requests());
        self::assertSame([], $waits);
    }

    public function testTheModelsOfTheKeyAndTheConnectionTest(): void
    {
        $models = self::provider(new SimulatedAnthropic())->models();
        self::assertSame(['claude-opus-5-5', 'claude-sonnet-5-5', 'claude-haiku-5-5'], array_map(static fn ($m): string => $m->id, $models));
        self::assertSame(['Claude Opus 5.5', 1000000, 128000], [$models[0]->displayName, $models[0]->maxInputTokens, $models[0]->maxTokens]);

        $haiku = self::provider(new SimulatedAnthropic(), new ClaudeConfig('sk-ant-haiku-only-1'))->testConnection();
        self::assertTrue($haiku->ok);
        self::assertSame('claude-haiku-5-5', $haiku->details['models']);

        $refused = self::provider(new SimulatedAnthropic(), new ClaudeConfig('sk-ant-revoked-key'))->testConnection();
        self::assertFalse($refused->ok);
        self::assertStringContainsString('refused the API key', $refused->message);
        self::assertStringNotContainsString('sk-ant-revoked-key', $refused->message);
    }

    public function testThePlainContractAnswersTextToUserTurnsOnly(): void
    {
        $api = new SimulatedAnthropic();
        $provider = self::provider($api);

        self::assertSame('Nothing changed since the last report.', $provider->complete('Answer briefly.', [['role' => 'user', 'content' => 'Anything new?']], 500));
        self::assertSame(500, $api->log()[0]['body']['max_tokens']);
        $this->expectException(InvalidArgumentException::class);
        $provider->complete('x', [['role' => 'user', 'content' => 'a'], ['role' => 'assistant', 'content' => '{']], 100);
    }

    public function testTheGuardLetsOnlyTheTwoRoutesOfTheAssistantLeave(): void
    {
        $inner = new ScriptedHttpClient(static fn (): \Ritmo\Contracts\HttpResponse => ScriptedHttpClient::json(200, ['ok' => true]));
        $guard = new AiHttpGuard($inner);

        $guard->request('POST', 'https://api.anthropic.com/v1/messages', [], '{}');
        $guard->request('GET', 'https://api.anthropic.com/v1/models?limit=100');
        $refused = [
            ['POST', 'https://api.anthropic.com/v1/models', []],
            ['GET', 'https://api.anthropic.com/v1/messages', []],
            ['POST', 'https://api.anthropic.com/v1/messages/batches', []],
            ['POST', 'https://api.pricelabs.co/v1/listing_prices', []],
            ['DELETE', 'https://api.anthropic.com/v1/messages', []],
            ['POST', 'https://api.anthropic.com.evil.test/v1/messages', []],
            ['POST', 'https://api.anthropic.com/v1/messages', ['X-HTTP-Method-Override' => 'DELETE']],
            ['GET', 'https://api.anthropic.com/v1/models?after_id=x', []],
        ];
        foreach ($refused as [$method, $url, $headers]) {
            try {
                $guard->request($method, $url, $headers);
                self::fail($method . ' ' . $url . ' must be refused');
            } catch (WriteBlocked) {
            }
        }
        self::assertSame(['POST /v1/messages', 'GET /v1/models'], array_map(static fn (array $r): string => $r['method'] . ' ' . $r['path'], $inner->requests()));
    }

    public function testTheKeyNeverAppearsInADumpAndCannotBeSerialised(): void
    {
        $config = new ClaudeConfig(self::KEY);
        self::assertStringNotContainsString(self::KEY, print_r($config, true));
        self::assertStringNotContainsString(self::KEY, var_export($config->__debugInfo(), true));
        $this->expectException(LogicException::class);
        serialize($config);
    }

    public function testTheConfigRefusesABadKeyAnUnknownModelAndAForeignBaseUrl(): void
    {
        foreach ([['', 'claude-opus-5-5'], ["sk-ant-a b", 'claude-opus-5-5'], [self::KEY, 'gpt-4'], [self::KEY, 'claude opus']] as [$key, $model]) {
            try {
                new ClaudeConfig($key, $model);
                self::fail('refused: ' . $model);
            } catch (InvalidArgumentException) {
            }
        }
        if (!defined('RITMO_DEV')) {
            $this->expectException(InvalidArgumentException::class);
            new ClaudeConfig(self::KEY, 'claude-opus-5-5', AiEffort::Medium, true, 120, 'http://127.0.0.1:9/anthropic');
        } else {
            self::assertTrue(true);
        }
    }

    /**
     * @param list<float>                 $waits
     * @param (\Closure(): ?float)|null   $timeLeft
     */
    private static function provider(\Ritmo\Contracts\HttpClient $http, ?ClaudeConfig $config = null, array &$waits = [], ?\Closure $timeLeft = null): ClaudeProvider
    {
        $config ??= new ClaudeConfig(self::KEY);

        return new ClaudeProvider(new ClaudeClient(new AiHttpGuard($http), $config, new RecordingLogger(), static function (float $s) use (&$waits): void {
            $waits[] = $s;
        }, $timeLeft));
    }

    private static function report(): AiRequest
    {
        return new AiRequest(AiPurpose::WeeklyReport, PromptLibrary::system(AiPurpose::WeeklyReport), "Locale: en. Write in English.\n<data>\n{\"facts\":[{\"label\":\"Bookings made\",\"value\":\"4\"}]}\n</data>");
    }
}
