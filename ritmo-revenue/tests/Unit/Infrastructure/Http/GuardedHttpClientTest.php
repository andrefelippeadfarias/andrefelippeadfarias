<?php

declare(strict_types=1);

namespace Ritmo\Tests\Unit\Infrastructure\Http;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Ritmo\Contracts\Exception\ConnectorException;
use Ritmo\Contracts\Exception\WriteBlocked;
use Ritmo\Contracts\HttpResponse;
use Ritmo\Contracts\Logger;
use Ritmo\Infrastructure\Http\GuardedHttpClient;
use Ritmo\Infrastructure\Http\ReadRouteAllowList;
use Ritmo\Infrastructure\Log\MemoryLogger;
use Ritmo\Tests\Unit\Infrastructure\Support\ScriptedHttpClient;

final class GuardedHttpClientTest extends TestCase
{
    private ScriptedHttpClient $inner;

    private MemoryLogger $logger;

    protected function setUp(): void
    {
        $this->inner = new ScriptedHttpClient(new HttpResponse(200, ['Content-Type' => 'application/json'], '{"ok":true}'));
        $this->logger = new MemoryLogger();
    }

    public function testAGetPassesThroughUnchanged(): void
    {
        $client = $this->client(ReadRouteAllowList::none());

        $response = $client->request('get', 'https://api.pms.example/v2/bookings?arrivalFrom=2026-12-01', ['token' => 'abc'], null, 45);

        self::assertSame(['ok' => true], $response->json());
        self::assertSame(
            [['method' => 'GET', 'url' => 'https://api.pms.example/v2/bookings?arrivalFrom=2026-12-01', 'headers' => ['token' => 'abc'], 'body' => null, 'timeout' => 45]],
            $this->inner->requests
        );
        self::assertSame([], $client->blockedAttempts());
        self::assertSame([], $this->logger->entries());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function mutatingMethods(): array
    {
        return [
            'POST' => ['POST'], 'PUT' => ['PUT'], 'PATCH' => ['PATCH'], 'DELETE' => ['DELETE'],
            'lowercase post' => ['post'], 'padded delete' => [' delete '],
            'HEAD is not GET either' => ['HEAD'], 'OPTIONS' => ['OPTIONS'], 'unknown verb' => ['PURGE'],
        ];
    }

    #[DataProvider('mutatingMethods')]
    public function testEveryOtherMethodIsBlockedBeforeItLeaves(string $method): void
    {
        $client = $this->client(ReadRouteAllowList::none());

        try {
            $client->request($method, 'https://api.pricing.example/v1/listings/77/overrides?api_key=SECRET', ['X-API-Key' => 'SECRET'], '{"price":1}');
            self::fail("$method must be blocked.");
        } catch (WriteBlocked $blocked) {
            self::assertStringContainsString('api.pricing.example/v1/listings/77/overrides', $blocked->getMessage());
            self::assertStringNotContainsString('SECRET', $blocked->getMessage());
            self::assertNotInstanceOf(ConnectorException::class, $blocked, 'a block is never a retryable connector failure');
        }

        self::assertSame([], $this->inner->requests, 'nothing reached the network');
        self::assertCount(1, $client->blockedAttempts());
        self::assertSame(strtoupper(trim($method)), $client->blockedAttempts()[0]['method']);
        self::assertSame('api.pricing.example', $client->blockedAttempts()[0]['host']);
        self::assertSame('/v1/listings/77/overrides', $client->blockedAttempts()[0]['path']);
    }

    public function testABlockedAttemptIsLoggedWithoutCredentialsOrBody(): void
    {
        $client = $this->client(ReadRouteAllowList::none());

        try {
            $client->request('DELETE', 'https://api.pricing.example/v1/overrides?api_key=SECRET-IN-QUERY', ['X-API-Key' => 'SECRET-IN-HEADER'], 'SECRET-IN-BODY');
        } catch (WriteBlocked) {
        }

        $entries = $this->logger->entries(Logger::WARNING);
        self::assertCount(1, $entries);
        self::assertSame('Blocked an outgoing request that could change an external system.', $entries[0]['message']);
        self::assertSame(['method', 'host', 'path', 'reason'], array_keys($entries[0]['context']));
        self::assertSame(['DELETE', 'api.pricing.example', '/v1/overrides'], array_slice(array_values($entries[0]['context']), 0, 3));
        self::assertStringNotContainsString('SECRET', (string) json_encode($entries));
    }

    public function testAPostMarkedAsAReadPassesOnlyOnItsExactHostAndPath(): void
    {
        $client = $this->client(ReadRouteAllowList::none()->with('pricing', 'POST', 'api.pricing.example', '/v1/listing_prices'));

        $client->request('POST', 'https://api.pricing.example/v1/listing_prices', [], '{"listings":[]}');
        $client->request('post', 'https://API.Pricing.Example:443/v1/listing_prices?x=1', [], '{}');
        self::assertCount(2, $this->inner->requests);

        $refused = [
            'another path' => ['POST', 'https://api.pricing.example/v1/listings'],
            'a longer path' => ['POST', 'https://api.pricing.example/v1/listing_prices/77'],
            'a trailing slash' => ['POST', 'https://api.pricing.example/v1/listing_prices/'],
            'a path that climbs back' => ['POST', 'https://api.pricing.example/v1/listing_prices/../overrides'],
            'an encoded path' => ['POST', 'https://api.pricing.example/v1/listing%5Fprices'],
            'a doubled slash' => ['POST', 'https://api.pricing.example//v1/listing_prices'],
            'another host' => ['POST', 'https://api.other.example/v1/listing_prices'],
            'a host that only starts the same' => ['POST', 'https://api.pricing.example.evil.test/v1/listing_prices'],
            'the host as user name' => ['POST', 'https://api.pricing.example@evil.test/v1/listing_prices'],
            'another port' => ['POST', 'https://api.pricing.example:8443/v1/listing_prices'],
            'a trailing dot host' => ['POST', 'https://api.pricing.example./v1/listing_prices'],
            'another method on the same route' => ['PUT', 'https://api.pricing.example/v1/listing_prices'],
            'DELETE on the same route' => ['DELETE', 'https://api.pricing.example/v1/listing_prices'],
        ];
        foreach ($refused as $case => [$method, $url]) {
            try {
                $client->request($method, $url, [], '{}');
                self::fail("$case must be blocked.");
            } catch (WriteBlocked) {
            }
        }

        self::assertCount(2, $this->inner->requests);
        self::assertCount(count($refused), $client->blockedAttempts());
    }

    public function testARouteOnALocalFakeServerIsMatchedWithItsPort(): void
    {
        $client = $this->client(ReadRouteAllowList::none()->with('pricing', 'POST', '127.0.0.1:8123', '/v1/listing_prices'));

        $client->request('POST', 'http://127.0.0.1:8123/v1/listing_prices', [], '{}');

        $this->expectException(WriteBlocked::class);
        $client->request('POST', 'http://127.0.0.1:8124/v1/listing_prices', [], '{}');
    }

    /**
     * @return array<string, array{string, array<string, string>}>
     */
    public static function overrides(): array
    {
        return [
            'GET turned into DELETE' => ['GET', ['X-HTTP-Method-Override' => 'DELETE']],
            'lowercase header' => ['GET', ['x-http-method-override' => 'PUT']],
            'another spelling' => ['GET', ['X-Method-Override' => 'PATCH']],
            'a third spelling' => ['GET', ['X-HTTP-Method' => 'DELETE']],
            'on an allowed POST' => ['POST', ['X-HTTP-Method-Override' => 'PUT']],
            'even when it says GET' => ['GET', ['X-HTTP-Method-Override' => 'GET']],
        ];
    }

    /**
     * @param array<string, string> $headers
     */
    #[DataProvider('overrides')]
    public function testAMethodOverrideHeaderIsBlocked(string $method, array $headers): void
    {
        $client = $this->client(ReadRouteAllowList::none()->with('pricing', 'POST', 'api.pricing.example', '/v1/listing_prices'));

        try {
            $client->request($method, 'https://api.pricing.example/v1/listing_prices', $headers);
            self::fail('A method override must be blocked.');
        } catch (WriteBlocked $blocked) {
            self::assertStringContainsString('method-override', $blocked->getMessage());
        }
        self::assertSame([], $this->inner->requests);
    }

    /**
     * @return array<string, array{string, string, array<string, string>}>
     */
    public static function malformed(): array
    {
        return [
            'a request line as method' => ["GET /x HTTP/1.1\r\nHost: evil", 'https://api.pms.example/v2/bookings', []],
            'an empty method' => ['', 'https://api.pms.example/v2/bookings', []],
            'a relative URL' => ['GET', '/v2/bookings', []],
            'another scheme' => ['GET', 'ftp://api.pms.example/v2/bookings', []],
            'a file URL' => ['GET', 'file:///etc/passwd', []],
            'a URL with a line break' => ['GET', "https://api.pms.example/v2/bookings\r\nX-Injected: 1", []],
            'a URL with a space' => ['GET', 'https://api.pms.example/v2/book ings', []],
            'a header value with a line break' => ['GET', 'https://api.pms.example/v2/bookings', ['Accept' => "x\r\nX-HTTP-Method-Override: DELETE"]],
            'a header name with a line break' => ['GET', 'https://api.pms.example/v2/bookings', ["Accept: x\r\nX-HTTP-Method-Override" => 'DELETE']],
            'a header name with a colon' => ['GET', 'https://api.pms.example/v2/bookings', ['X-HTTP-Method-Override: DELETE' => '']],
        ];
    }

    /**
     * @param array<string, string> $headers
     */
    #[DataProvider('malformed')]
    public function testARequestThatCouldSmuggleAnotherIsRefused(string $method, string $url, array $headers): void
    {
        $client = $this->client(ReadRouteAllowList::none());

        try {
            $client->request($method, $url, $headers);
            self::fail('The malformed request must be refused.');
        } catch (InvalidArgumentException) {
        }
        self::assertSame([], $this->inner->requests);
    }

    public function testTheAllowListTakesOnlyExactPostRoutes(): void
    {
        $list = ReadRouteAllowList::none();
        self::assertSame([], $list->routes());
        self::assertFalse($list->allows('POST', 'api.pricing.example', '/v1/listing_prices'));

        $bad = [
            'GET needs no entry' => ['pricing', 'GET', 'api.pricing.example', '/v1/x'],
            'PUT is never a read' => ['pricing', 'PUT', 'api.pricing.example', '/v1/x'],
            'PATCH is never a read' => ['pricing', 'PATCH', 'api.pricing.example', '/v1/x'],
            'DELETE is never a read' => ['pricing', 'DELETE', 'api.pricing.example', '/v1/x'],
            'lowercase method' => ['pricing', 'post', 'api.pricing.example', '/v1/x'],
            'no connector' => ['', 'POST', 'api.pricing.example', '/v1/x'],
            'wildcard host' => ['pricing', 'POST', '*.pricing.example', '/v1/x'],
            'uppercase host' => ['pricing', 'POST', 'API.pricing.example', '/v1/x'],
            'host with scheme' => ['pricing', 'POST', 'https://api.pricing.example', '/v1/x'],
            'empty host' => ['pricing', 'POST', '', '/v1/x'],
            'wildcard path' => ['pricing', 'POST', 'api.pricing.example', '/v1/*'],
            'root path' => ['pricing', 'POST', 'api.pricing.example', '/'],
            'relative path' => ['pricing', 'POST', 'api.pricing.example', 'v1/x'],
            'path with query' => ['pricing', 'POST', 'api.pricing.example', '/v1/x?y=1'],
            'path that climbs' => ['pricing', 'POST', 'api.pricing.example', '/v1/../x'],
            'path with a regex' => ['pricing', 'POST', 'api.pricing.example', '/v1/.+'],
        ];
        foreach ($bad as $case => $route) {
            try {
                $list->with(...$route);
                self::fail("$case must be refused.");
            } catch (InvalidArgumentException) {
            }
        }

        $extended = $list->with('pricing', 'POST', 'api.pricing.example', '/v1/listing_prices');
        self::assertSame([], $list->routes(), 'the list is immutable');
        self::assertSame([['connector' => 'pricing', 'method' => 'POST', 'host' => 'api.pricing.example', 'path' => '/v1/listing_prices']], $extended->routes());
        self::assertTrue($extended->allows('POST', 'api.pricing.example', '/v1/listing_prices'));
    }

    private function client(ReadRouteAllowList $routes): GuardedHttpClient
    {
        return new GuardedHttpClient($this->inner, $routes, $this->logger);
    }
}
