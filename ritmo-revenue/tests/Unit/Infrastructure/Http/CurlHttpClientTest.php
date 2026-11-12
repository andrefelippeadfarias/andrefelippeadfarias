<?php

declare(strict_types=1);

namespace Ritmo\Tests\Unit\Infrastructure\Http;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Ritmo\Contracts\Exception\ConnectorException;
use Ritmo\Contracts\Exception\WriteBlocked;
use Ritmo\Infrastructure\Http\CurlHttpClient;
use Ritmo\Infrastructure\Http\GuardedHttpClient;
use Ritmo\Infrastructure\Http\ReadRouteAllowList;
use Ritmo\Infrastructure\Http\RequestTarget;
use Ritmo\Infrastructure\Log\MemoryLogger;
use Ritmo\Tests\Unit\Infrastructure\Support\LocalServer;

/**
 * Talks to PHP's built-in server on 127.0.0.1 only.
 */
final class CurlHttpClientTest extends TestCase
{
    private static LocalServer $server;

    public static function setUpBeforeClass(): void
    {
        self::$server = LocalServer::start(__DIR__ . '/server/echo.php');
    }

    public static function tearDownAfterClass(): void
    {
        self::$server->stop();
    }

    public function testAGetArrivesWithItsHeadersAndTheResponseComesBackWhole(): void
    {
        $response = (new CurlHttpClient('RitmoTest/1.0'))->request('GET', self::$server->url('/echo?from=2026-12-01&to=2026-12-31'), ['token' => 'abc', 'Accept' => 'application/json']);

        self::assertSame(200, $response->status());
        self::assertTrue($response->isSuccess());
        self::assertSame('application/json', $response->header('Content-Type'));
        self::assertSame('42', $response->header('x-five-min-limit-remaining'));
        self::assertSame('one, two', $response->header('X-Echo'), 'repeated headers are joined');

        $seen = $response->json();
        self::assertSame('GET', $seen['method']);
        self::assertSame('/echo?from=2026-12-01&to=2026-12-31', $seen['uri']);
        self::assertSame('abc', $seen['headers']['token']);
        self::assertSame('RitmoTest/1.0', $seen['headers']['user-agent']);
        self::assertSame('', $seen['body']);
    }

    public function testABodyIsSentAsGiven(): void
    {
        $body = '{"listings":[{"id":"77","dateFrom":"2026-12-01"}]}';

        $seen = (new CurlHttpClient())->request('POST', self::$server->url('/v1/listing_prices'), ['Content-Type' => 'application/json'], $body)->json();

        self::assertSame('POST', $seen['method']);
        self::assertSame($body, $seen['body']);
        self::assertSame('application/json', $seen['headers']['content-type']);
    }

    public function testErrorStatusesAreReturnedNotThrown(): void
    {
        $client = new CurlHttpClient();

        foreach ([401, 404, 429, 503] as $status) {
            $response = $client->request('GET', self::$server->url('/status/' . $status));
            self::assertSame($status, $response->status());
            self::assertFalse($response->isSuccess());
        }
    }

    public function testRedirectsAreNotFollowed(): void
    {
        $response = (new CurlHttpClient())->request('GET', self::$server->url('/redirect'));

        self::assertSame(302, $response->status());
        self::assertSame('/echo?redirected=1', $response->header('Location'));
    }

    public function testABodyThatIsNotJsonIsAConnectorError(): void
    {
        $response = (new CurlHttpClient())->request('GET', self::$server->url('/not-json'));
        self::assertSame('plain text', $response->body());

        $this->expectException(ConnectorException::class);
        $response->json();
    }

    public function testNoResponseIsARetryableConnectorError(): void
    {
        $url = 'http://127.0.0.1:' . LocalServer::freePort() . '/v2/bookings?token=SECRET';

        try {
            (new CurlHttpClient())->request('GET', $url, [], null, 2);
            self::fail('A refused connection must throw.');
        } catch (ConnectorException $failure) {
            self::assertTrue($failure->isRetryable());
            self::assertNull($failure->httpStatus());
            self::assertStringStartsWith('No response from 127.0.0.1:', $failure->getMessage());
            self::assertStringNotContainsString('SECRET', $failure->getMessage());
        }
    }

    public function testMalformedRequestsAreRefusedBeforeAnythingIsSent(): void
    {
        $client = new CurlHttpClient();
        foreach ([
            fn () => $client->request("GET /x HTTP/1.1\r\nX: y", self::$server->url('/echo')),
            fn () => $client->request('GET', 'file:///etc/hostname'),
            fn () => $client->request('GET', self::$server->url('/echo'), ['X-A' => "b\r\nX-C: d"]),
        ] as $call) {
            try {
                $call();
                self::fail('The request must be refused.');
            } catch (InvalidArgumentException) {
            }
        }
        $this->addToAssertionCount(3);
    }

    public function testBehindTheGuardAWriteNeverReachesTheServerAndAMarkedReadDoes(): void
    {
        $host = '127.0.0.1:' . self::$server->port;
        $client = new GuardedHttpClient(new CurlHttpClient(), ReadRouteAllowList::none()->with('pricing', 'POST', $host, '/v1/listing_prices'), new MemoryLogger());

        self::assertSame('GET', $client->request('GET', self::$server->url('/v1/listings'))->json()['method']);
        self::assertSame('POST', $client->request('POST', self::$server->url('/v1/listing_prices'), [], '{}')->json()['method']);

        foreach (['POST', 'PUT', 'PATCH', 'DELETE'] as $method) {
            try {
                $client->request($method, self::$server->url('/v1/listings/77/overrides'), [], '{}');
                self::fail("$method must be blocked.");
            } catch (WriteBlocked) {
            }
        }
        self::assertCount(4, $client->blockedAttempts());
    }

    public function testATargetKeepsHostPortAndPathAndDropsTheQuery(): void
    {
        $target = RequestTarget::fromUrl('HTTPS://user:pass@API.Example.com:8443/v1/Things?token=SECRET#frag');

        self::assertSame(['https', 'api.example.com:8443', '/v1/Things'], [$target->scheme, $target->host, $target->path]);
        self::assertSame('api.example.com:8443/v1/Things', (string) $target);
        self::assertSame('api.example.com', RequestTarget::fromUrl('https://api.example.com:443')->host);
        self::assertSame('/', RequestTarget::fromUrl('https://api.example.com')->path);
        self::assertSame('localhost', RequestTarget::fromUrl('http://localhost:80/x')->host);
        self::assertSame('localhost:443', RequestTarget::fromUrl('http://localhost:443/x')->host);
    }
}
