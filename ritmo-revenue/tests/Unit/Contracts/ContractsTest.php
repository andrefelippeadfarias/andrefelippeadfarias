<?php

declare(strict_types=1);

namespace Ritmo\Tests\Unit\Contracts;

use DateTimeImmutable;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use Ritmo\Contracts\AiProvider;
use Ritmo\Contracts\ConnectionStatus;
use Ritmo\Contracts\Exception\AuthFailed;
use Ritmo\Contracts\Exception\ConnectorException;
use Ritmo\Contracts\Exception\NotAvailableInThisMilestone;
use Ritmo\Contracts\Exception\RateLimited;
use Ritmo\Contracts\Exception\WriteBlocked;
use Ritmo\Contracts\HttpClient;
use Ritmo\Contracts\HttpResponse;
use Ritmo\Contracts\KeyValueStore;
use Ritmo\Contracts\LicenceProvider;
use Ritmo\Contracts\LicenceState;
use Ritmo\Contracts\Logger;
use Ritmo\Contracts\MarketDataProvider;
use Ritmo\Contracts\PmsConnector;
use Ritmo\Contracts\PricingConnector;
use Ritmo\Contracts\QuotaLedger;
use Ritmo\Contracts\Support\PmsWritesUnavailable;
use Ritmo\Contracts\Support\PricingWritesUnavailable;
use Ritmo\Contracts\WriteToken;
use RuntimeException;

final class ContractsTest extends TestCase
{
    /** Methods that change an external system. In the read-only milestone every one of them must throw. */
    private const PMS_WRITES = ['writeCalendar'];

    private const PRICING_WRITES = ['requestRecalculation', 'writeOverrides', 'deleteOverrides', 'updateListing'];

    /**
     * @param class-string $interface
     *
     * @return list<string>
     */
    private static function methods(string $interface): array
    {
        return array_map(static fn (ReflectionMethod $method): string => $method->getName(), (new ReflectionClass($interface))->getMethods());
    }

    public function testInterfacesExposeTheAgreedMethods(): void
    {
        $expected = [
            PmsConnector::class => ['id', 'testConnection', 'readBookingsByStay', 'readBookingsModifiedSince', 'readInventory', 'writeCalendar'],
            PricingConnector::class => ['id', 'testConnection', 'listListings', 'readPrices', 'readOverrides', 'requestRecalculation', 'writeOverrides', 'deleteOverrides', 'updateListing'],
            MarketDataProvider::class => ['id', 'readAnchors'],
            LicenceProvider::class => ['id', 'check'],
            AiProvider::class => ['id', 'complete'],
            HttpClient::class => ['request'],
            KeyValueStore::class => ['get', 'getFresh', 'has', 'set', 'delete'],
            QuotaLedger::class => ['record', 'countSince'],
            Logger::class => ['log', 'debug', 'info', 'warning', 'error'],
        ];

        foreach ($expected as $interface => $methods) {
            self::assertTrue((new ReflectionClass($interface))->isInterface(), "$interface must be an interface");
            self::assertSame($methods, self::methods($interface), $interface);
        }
    }

    public function testEveryWriteMethodRequiresAWriteTokenFirst(): void
    {
        $writes = [PmsConnector::class => self::PMS_WRITES, PricingConnector::class => self::PRICING_WRITES];
        foreach ($writes as $interface => $methods) {
            foreach ($methods as $method) {
                $first = (new ReflectionMethod($interface, $method))->getParameters()[0]->getType();
                self::assertInstanceOf(ReflectionNamedType::class, $first);
                self::assertSame(WriteToken::class, $first->getName(), "$interface::$method");
            }
        }
    }

    public function testNoReadMethodAsksForAWriteToken(): void
    {
        foreach ([PmsConnector::class => self::PMS_WRITES, PricingConnector::class => self::PRICING_WRITES] as $interface => $writes) {
            foreach (array_diff(self::methods($interface), $writes) as $method) {
                foreach ((new ReflectionMethod($interface, $method))->getParameters() as $parameter) {
                    self::assertNotSame(WriteToken::class, (string) $parameter->getType(), "$interface::$method");
                }
            }
        }
    }

    /**
     * Only the write gate creates a token (through the private constructor); a token has no factory,
     * cannot be cloned and grants nothing by itself (the gate's live registry does).
     */
    public function testAWriteTokenHasNoPublicWayIn(): void
    {
        $reflection = new ReflectionClass(WriteToken::class);

        self::assertTrue($reflection->isFinal());
        self::assertTrue($reflection->getConstructor()?->isPrivate());
        self::assertTrue($reflection->getMethod('__clone')->isPrivate());
        self::assertSame([], $reflection->getMethods(ReflectionMethod::IS_STATIC), 'no factory may exist: only the write gate issues tokens');
        self::assertSame(['__construct', '__clone', '__serialize', '__unserialize'], array_map(static fn (ReflectionMethod $m): string => $m->getName(), $reflection->getMethods()));
    }

    public function testAWriteTokenCannotBeRebuiltFromASerialisedString(): void
    {
        $class = WriteToken::class;

        $this->expectException(\LogicException::class);
        unserialize(sprintf('O:%d:"%s":0:{}', strlen($class), $class));
    }

    public function testThePmsWriteTraitThrowsForEveryWriteMethod(): void
    {
        $connector = new class () implements PmsConnector {
            use PmsWritesUnavailable;

            public function id(): string
            {
                return 'fake-pms';
            }

            public function testConnection(): ConnectionStatus
            {
                return new ConnectionStatus(true, 'ok');
            }

            public function readBookingsByStay(DateTimeImmutable $from, DateTimeImmutable $to): array
            {
                return [];
            }

            public function readBookingsModifiedSince(DateTimeImmutable $instant): array
            {
                return [];
            }

            public function readInventory(array $roomRefs, DateTimeImmutable $from, DateTimeImmutable $to, bool $withPrices = false): array
            {
                return [];
            }
        };

        self::assertSame(self::PMS_WRITES, self::methods(PmsWritesUnavailable::class));
        $this->assertEveryWriteThrows($connector, self::PMS_WRITES);
    }

    public function testThePricingWriteTraitThrowsForEveryWriteMethod(): void
    {
        $connector = new class () implements PricingConnector {
            use PricingWritesUnavailable;

            public function id(): string
            {
                return 'fake-pricing';
            }

            public function testConnection(): ConnectionStatus
            {
                return new ConnectionStatus(false, 'no key', ['listings' => 0]);
            }

            public function listListings(): array
            {
                return [];
            }

            public function readPrices(string $listingRef, DateTimeImmutable $from, DateTimeImmutable $to): array
            {
                return [];
            }

            public function readOverrides(string $listingRef, DateTimeImmutable $from, DateTimeImmutable $to): array
            {
                return [];
            }
        };

        self::assertSame(self::PRICING_WRITES, self::methods(PricingWritesUnavailable::class));
        self::assertFalse($connector->testConnection()->ok);
        self::assertSame(['listings' => 0], $connector->testConnection()->details);
        $this->assertEveryWriteThrows($connector, self::PRICING_WRITES);
    }

    /**
     * Calls each write method with a token forged by reflection (the only way to get one) and
     * placeholder arguments: the method must throw before doing anything.
     *
     * @param list<string> $methods
     */
    private function assertEveryWriteThrows(object $connector, array $methods): void
    {
        $token = (new ReflectionClass(WriteToken::class))->newInstanceWithoutConstructor();
        foreach ($methods as $method) {
            $arguments = [$token];
            foreach (array_slice((new ReflectionMethod($connector, $method))->getParameters(), 1) as $parameter) {
                $arguments[] = (string) $parameter->getType() === 'array' ? [] : 'listing-1';
            }
            try {
                $connector->$method(...$arguments);
                self::fail("$method must throw while the plugin is read-only");
            } catch (NotAvailableInThisMilestone $e) {
                self::assertStringContainsString($method, $e->getMessage());
            }
        }
    }

    public function testExceptionHierarchyKeepsBugsAndBlocksOutOfRetryLogic(): void
    {
        self::assertInstanceOf(RuntimeException::class, new ConnectorException('x'));
        self::assertInstanceOf(ConnectorException::class, new RateLimited('x'));
        self::assertInstanceOf(ConnectorException::class, new AuthFailed('x'));
        self::assertInstanceOf(LogicException::class, NotAvailableInThisMilestone::forOperation('x'));
        self::assertNotInstanceOf(ConnectorException::class, NotAvailableInThisMilestone::forOperation('x'));
        self::assertInstanceOf(RuntimeException::class, WriteBlocked::forRequest('post', 'x', 'y'));
        self::assertNotInstanceOf(ConnectorException::class, WriteBlocked::forRequest('post', 'x', 'y'));
    }

    public function testConnectorExceptionCarriesItsContext(): void
    {
        $previous = new RuntimeException('socket closed');
        $exception = new ConnectorException('Timed out', 'pricing', 504, true, $previous);

        self::assertSame('Timed out', $exception->getMessage());
        self::assertSame('pricing', $exception->provider());
        self::assertSame(504, $exception->httpStatus());
        self::assertTrue($exception->isRetryable());
        self::assertSame($previous, $exception->getPrevious());

        $plain = new ConnectorException('Unexpected answer');
        self::assertSame('', $plain->provider());
        self::assertNull($plain->httpStatus());
        self::assertFalse($plain->isRetryable());
    }

    public function testRateLimitedAndAuthFailed(): void
    {
        $limited = new RateLimited('Too many requests', 'pms', 240);
        self::assertSame(240, $limited->retryAfterSeconds());
        self::assertSame(429, $limited->httpStatus());
        self::assertTrue($limited->isRetryable());
        self::assertNull((new RateLimited('Quota spent', 'pricing'))->retryAfterSeconds());

        $auth = new AuthFailed('Key rejected', 'pricing', 403);
        self::assertSame(403, $auth->httpStatus());
        self::assertFalse($auth->isRetryable());
        self::assertSame(401, (new AuthFailed('Key rejected'))->httpStatus());
    }

    public function testBlockingExceptionsNameWhatWasStopped(): void
    {
        self::assertSame(
            '"PricingConnector::writeOverrides" is not available in this milestone: the plugin is read-only.',
            NotAvailableInThisMilestone::forOperation('PricingConnector::writeOverrides')->getMessage()
        );
        self::assertSame(
            'Blocked DELETE https://pricing.example/overrides: the plugin is read-only.',
            WriteBlocked::forRequest('delete', 'https://pricing.example/overrides', 'the plugin is read-only')->getMessage()
        );
    }

    public function testHttpResponse(): void
    {
        $response = new HttpResponse(200, ['Content-Type' => 'application/json', 'X-Request-Cost' => '1'], '{"data": [1, 2]}');

        self::assertSame(200, $response->status());
        self::assertTrue($response->isSuccess());
        self::assertSame('application/json', $response->header('content-type'));
        self::assertSame('1', $response->header('X-REQUEST-COST'));
        self::assertNull($response->header('retry-after'));
        self::assertSame(['content-type' => 'application/json', 'x-request-cost' => '1'], $response->headers());
        self::assertSame('{"data": [1, 2]}', $response->body());
        self::assertSame(['data' => [1, 2]], $response->json());

        foreach ([199 => false, 204 => true, 299 => true, 300 => false, 404 => false, 429 => false, 500 => false] as $status => $success) {
            self::assertSame($success, (new HttpResponse($status))->isSuccess(), (string) $status);
        }
        self::assertSame('', (new HttpResponse(204))->body());
    }

    public function testHttpResponseJsonRejectsWhatIsNotAnObjectOrList(): void
    {
        foreach (['', 'not json', '"text"', '42', 'null'] as $body) {
            try {
                (new HttpResponse(502, [], $body))->json();
                self::fail('Expected a ConnectorException for: ' . $body);
            } catch (ConnectorException $e) {
                self::assertSame(502, $e->httpStatus());
                self::assertStringNotContainsString($body === '' ? 'never' : $body, $e->getMessage(), 'the body must not leak into the message');
            }
        }
    }

    public function testLicenceState(): void
    {
        $checked = new DateTimeImmutable('2026-11-11T10:00:00Z');
        $expires = new DateTimeImmutable('2027-11-11T00:00:00Z');

        $valid = new LicenceState(LicenceState::VALID, $expires, $checked, 'Active');
        self::assertTrue($valid->isValid());
        self::assertSame($expires, $valid->expiresAt);
        self::assertSame($checked, $valid->checkedAt);
        foreach ([LicenceState::EXPIRED, LicenceState::INVALID, LicenceState::UNREACHABLE] as $status) {
            self::assertFalse((new LicenceState($status, null, $checked))->isValid());
        }

        $this->expectException(InvalidArgumentException::class);
        new LicenceState('trial', null, $checked);
    }

    public function testLoggerLevels(): void
    {
        self::assertSame(['debug', 'info', 'warning', 'error'], [Logger::DEBUG, Logger::INFO, Logger::WARNING, Logger::ERROR]);
    }
}
