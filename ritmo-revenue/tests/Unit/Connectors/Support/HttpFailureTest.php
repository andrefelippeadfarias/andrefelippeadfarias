<?php

declare(strict_types=1);

namespace Ritmo\Tests\Unit\Connectors\Support;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Ritmo\Connectors\Support\HttpFailure;
use Ritmo\Connectors\Support\RequestBudget;
use Ritmo\Contracts\Exception\AuthFailed;
use Ritmo\Contracts\Exception\ConnectorException;
use Ritmo\Contracts\Exception\RateLimited;
use Ritmo\Tests\Fakes\MemoryQuotaLedger;
use Ritmo\Tests\Fakes\MovableClock;

final class HttpFailureTest extends TestCase
{
    public function testEachStatusBecomesItsTypedException(): void
    {
        foreach ([401, 403] as $status) {
            $e = HttpFailure::forStatus('pricelabs', 'PriceLabs', 'GET /listings', $status, null, 'API_KEY_INVALID');
            self::assertInstanceOf(AuthFailed::class, $e);
            self::assertSame($status, $e->httpStatus());
            self::assertFalse($e->isRetryable());
            self::assertSame('pricelabs', $e->provider());
            self::assertStringContainsString('API_KEY_INVALID', $e->getMessage());
        }

        $limited = HttpFailure::forStatus('beds24', 'Beds24', 'GET /bookings', 429, 120);
        self::assertInstanceOf(RateLimited::class, $limited);
        self::assertSame(120, $limited->retryAfterSeconds());
        self::assertSame(429, $limited->httpStatus());

        foreach ([500 => true, 503 => true, 408 => true, 400 => false, 404 => false] as $status => $retryable) {
            $e = HttpFailure::forStatus('beds24', 'Beds24', 'GET /bookings', $status);
            self::assertSame(ConnectorException::class, $e::class);
            self::assertSame($retryable, $e->isRetryable(), (string) $status);
            self::assertSame('Beds24 answered with an error (HTTP ' . $status . ' on GET /bookings).', $e->getMessage());
        }
    }

    public function testOnlyCodeShapedTextIsShown(): void
    {
        self::assertSame('LISTING_NO_DATA', HttpFailure::safeCode('LISTING_NO_DATA'));
        foreach (['listing no data', 'Bearer abc', '', 'X', 42, null, str_repeat('A', 80)] as $candidate) {
            self::assertNull(HttpFailure::safeCode($candidate), var_export($candidate, true));
        }
    }

    public function testABudgetRefusesBeforeSendingOnceItsWindowIsFull(): void
    {
        $ledger = new MemoryQuotaLedger();
        $clock = new MovableClock('2027-03-01T10:00:00Z');
        $budget = new RequestBudget($ledger, $clock, 'pricelabs', 'PriceLabs', 3, 3600);

        for ($call = 0; $call < 3; ++$call) {
            $budget->assertOpen();
            $budget->record($call !== 1);
            $clock->advance(600);
        }
        self::assertSame([true, false, true], array_column($ledger->calls(), 'succeeded'), 'a failed call still counts');
        self::assertSame(['pricelabs'], array_values(array_unique(array_column($ledger->calls(), 'provider'))));
        self::assertSame([RequestBudget::ACTION], array_values(array_unique(array_column($ledger->calls(), 'action'))));

        try {
            $budget->assertOpen();
            self::fail('A full window must refuse.');
        } catch (RateLimited $e) {
            self::assertSame(3600, $e->retryAfterSeconds());
            self::assertNull($e->httpStatus(), 'nothing was sent');
            self::assertStringContainsString('no request was sent', $e->getMessage());
        }

        $clock->advance(1800 + 1);
        $budget->assertOpen();
        self::addToAssertionCount(1);
    }

    public function testABudgetNeedsAPositiveLimitAndWindow(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new RequestBudget(new MemoryQuotaLedger(), new MovableClock('2027-03-01T10:00:00Z'), 'x', 'X', 0, 60);
    }
}
