<?php

declare(strict_types=1);

namespace Ritmo\Infrastructure\Http {
    use Ritmo\Tests\Unit\Infrastructure\Support\WpErrorDouble;
    use Ritmo\Tests\Unit\Infrastructure\Support\WpFunctions;

    if (!function_exists(__NAMESPACE__ . '\wp_remote_request')) {
        /**
         * @param array<string, mixed> $args
         */
        function wp_remote_request(string $url, array $args = []): mixed
        {
            WpFunctions::$requests[] = [$url, $args];

            return WpFunctions::$response;
        }

        function is_wp_error(mixed $thing): bool
        {
            return $thing instanceof WpErrorDouble;
        }

        /**
         * @param array<string, mixed> $response
         */
        function wp_remote_retrieve_response_code(array $response): int|string
        {
            return $response['response']['code'] ?? '';
        }

        /**
         * @param array<string, mixed> $response
         *
         * @return iterable<string, string|list<string>>
         */
        function wp_remote_retrieve_headers(array $response): iterable
        {
            return $response['headers'] ?? [];
        }

        /**
         * @param array<string, mixed> $response
         */
        function wp_remote_retrieve_body(array $response): string
        {
            return $response['body'] ?? '';
        }
    }
}

namespace Ritmo\Tests\Unit\Infrastructure\Http {
    use ArrayIterator;
    use InvalidArgumentException;
    use PHPUnit\Framework\TestCase;
    use Ritmo\Contracts\Exception\ConnectorException;
    use Ritmo\Contracts\Exception\WriteBlocked;
    use Ritmo\Infrastructure\Http\GuardedHttpClient;
    use Ritmo\Infrastructure\Http\ReadRouteAllowList;
    use Ritmo\Infrastructure\Http\WpHttpClient;
    use Ritmo\Infrastructure\Log\MemoryLogger;
    use Ritmo\Tests\Unit\Infrastructure\Support\WpErrorDouble;
    use Ritmo\Tests\Unit\Infrastructure\Support\WpFunctions;

    final class WpHttpClientTest extends TestCase
    {
        protected function setUp(): void
        {
            WpFunctions::reset();
        }

        protected function tearDown(): void
        {
            WpFunctions::reset();
        }

        public function testTheRequestIsHandedToWordPressWithoutRedirects(): void
        {
            WpFunctions::$response = ['response' => ['code' => 200], 'headers' => ['content-type' => 'application/json'], 'body' => '{"ok":true}'];

            $response = (new WpHttpClient('RitmoTest/1.0'))->request('get', 'https://api.pms.example/v2/bookings?arrivalFrom=2026-12-01', ['token' => 'abc'], null, 45);

            self::assertSame(['ok' => true], $response->json());
            self::assertSame(
                [['https://api.pms.example/v2/bookings?arrivalFrom=2026-12-01', [
                    'method' => 'GET', 'headers' => ['token' => 'abc'], 'body' => null, 'timeout' => 45, 'redirection' => 0, 'user-agent' => 'RitmoTest/1.0',
                ]]],
                WpFunctions::$requests
            );
        }

        public function testHeadersComeBackLowercaseAndRepeatedOnesJoined(): void
        {
            // WordPress answers with a case-insensitive dictionary object: any iterable works.
            WpFunctions::$response = [
                'response' => ['code' => '429'],
                'headers' => new ArrayIterator(['X-Five-Min-Limit-Remaining' => '0', 'Set-Cookie' => ['a=1', 'b=2']]),
                'body' => 'slow down',
            ];

            $response = (new WpHttpClient())->request('GET', 'https://api.pms.example/v2/bookings');

            self::assertSame(429, $response->status());
            self::assertSame('0', $response->header('x-five-min-limit-remaining'));
            self::assertSame('a=1, b=2', $response->header('set-cookie'));
            self::assertSame('slow down', $response->body());
        }

        public function testABodyAndItsMethodAreSentAsGiven(): void
        {
            WpFunctions::$response = ['response' => ['code' => 200], 'headers' => [], 'body' => '[]'];

            (new WpHttpClient())->request('POST', 'https://api.pricing.example/v1/listing_prices', ['Content-Type' => 'application/json'], '{"listings":[]}');

            self::assertSame('POST', WpFunctions::$requests[0][1]['method']);
            self::assertSame('{"listings":[]}', WpFunctions::$requests[0][1]['body']);
        }

        public function testAWordPressErrorIsARetryableConnectorError(): void
        {
            WpFunctions::$response = new WpErrorDouble('cURL error 28: Operation timed out.');

            try {
                (new WpHttpClient())->request('GET', 'https://api.pms.example/v2/bookings?token=SECRET');
                self::fail('A WordPress error must throw.');
            } catch (ConnectorException $failure) {
                self::assertTrue($failure->isRetryable());
                self::assertNull($failure->httpStatus());
                self::assertSame('No response from api.pms.example: cURL error 28: Operation timed out.', $failure->getMessage());
            }
        }

        public function testMalformedRequestsNeverReachWordPress(): void
        {
            $client = new WpHttpClient();
            foreach ([
                static fn () => $client->request("GET /x HTTP/1.1\r\nX: y", 'https://api.pms.example/v2/bookings'),
                static fn () => $client->request('GET', '/relative'),
                static fn () => $client->request('GET', 'https://api.pms.example/v2/bookings', ['X-A' => "b\r\nX-C: d"]),
            ] as $call) {
                try {
                    $call();
                    self::fail('The request must be refused.');
                } catch (InvalidArgumentException) {
                }
            }

            self::assertSame([], WpFunctions::$requests);
        }

        public function testBehindTheGuardAWriteNeverReachesWordPress(): void
        {
            WpFunctions::$response = ['response' => ['code' => 200], 'headers' => [], 'body' => '{}'];
            $client = new GuardedHttpClient(new WpHttpClient(), ReadRouteAllowList::none(), new MemoryLogger());

            foreach (['POST', 'PUT', 'PATCH', 'DELETE'] as $method) {
                try {
                    $client->request($method, 'https://api.pricing.example/v1/listings/77/overrides', [], '{}');
                    self::fail("$method must be blocked.");
                } catch (WriteBlocked) {
                }
            }
            self::assertSame([], WpFunctions::$requests);

            $client->request('GET', 'https://api.pricing.example/v1/listings');
            self::assertCount(1, WpFunctions::$requests);
        }
    }
}
