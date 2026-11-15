<?php

declare(strict_types=1);

namespace Ritmo\Tests\Unit\Infrastructure\Http;

use PHPUnit\Framework\TestCase;
use Ritmo\Contracts\Exception\WriteBlocked;
use Ritmo\Contracts\HttpResponse;
use Ritmo\Infrastructure\Http\GuardedHttpClient;
use Ritmo\Infrastructure\Http\HostBoundHttpClient;
use Ritmo\Infrastructure\Http\ReadRouteAllowList;
use Ritmo\Tests\Fakes\RecordingLogger;
use Ritmo\Tests\Fakes\ScriptedHttpClient;

/**
 * The licence and update channels reach only the server of their own constant: another host never
 * gets the request (nor the licence key in its body), and no server lets nothing leave.
 */
final class HostBoundHttpClientTest extends TestCase
{
    public function testOnlyTheConfiguredHostIsReachedAndAnotherHostIsRefusedBeforeTheWire(): void
    {
        $inner = new ScriptedHttpClient(static fn (): HttpResponse => new HttpResponse(200, [], '{}'));
        $logger = new RecordingLogger();
        $client = new HostBoundHttpClient($inner, HostBoundHttpClient::hostsOf(['https://licence.example.com/api', null]), $logger, 'licence');

        self::assertSame(200, $client->request('POST', 'https://licence.example.com/api/validate', [], '{"licence_key":"K"}')->status());
        foreach (['https://other.example.com/api/validate', 'https://licence.example.com:8443/api/validate', 'https://api.anthropic.com/v1/messages', 'https://licence.example.com.evil.test/api'] as $url) {
            try {
                $client->request('POST', $url, [], '{"licence_key":"K"}');
                self::fail($url . ' is not the licence server');
            } catch (WriteBlocked) {
            }
        }

        self::assertSame(['POST /api/validate'], $inner->lines());
        self::assertCount(4, $logger->entries('warning'));
        self::assertStringNotContainsString('licence_key', $logger->dump(), 'the log never carries the body');
    }

    public function testWithoutAServerNothingLeaves(): void
    {
        $inner = new ScriptedHttpClient(static fn (): HttpResponse => new HttpResponse(200, [], '{}'));
        $client = new HostBoundHttpClient($inner, HostBoundHttpClient::hostsOf([null, '']), new RecordingLogger(), 'update');

        $this->expectException(WriteBlocked::class);
        try {
            $client->request('GET', 'https://updates.example.com/ritmo-revenue.json');
        } finally {
            self::assertSame([], $inner->lines());
        }
    }

    public function testBehindTheReadOnlyGuardOnlyTheDeclaredPostsOfThatHostLeave(): void
    {
        $inner = new ScriptedHttpClient(static fn (): HttpResponse => new HttpResponse(200, [], '{}'));
        $logger = new RecordingLogger();
        $routes = ReadRouteAllowList::none()->with('licence', 'POST', '127.0.0.1:8093', '/licence/activate');
        $client = new HostBoundHttpClient(new GuardedHttpClient($inner, $routes, $logger), HostBoundHttpClient::hostsOf(['http://127.0.0.1:8093/licence']), $logger, 'licence');

        $client->request('POST', 'http://127.0.0.1:8093/licence/activate', [], '{}');
        $client->request('GET', 'http://127.0.0.1:8093/updates.json');
        try {
            $client->request('DELETE', 'http://127.0.0.1:8093/licence/activate');
            self::fail('only the declared POST is a read');
        } catch (WriteBlocked) {
        }

        self::assertSame(['POST /licence/activate', 'GET /updates.json'], $inner->lines());
    }
}
