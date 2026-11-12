<?php

declare(strict_types=1);

namespace Ritmo\Tests\Unit\Connectors\Beds24;

use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\TestCase;
use Ritmo\Connectors\Beds24\Beds24Config;
use Ritmo\Connectors\Beds24\Beds24Credentials;
use Ritmo\Connectors\Beds24\CreditWindow;
use Ritmo\Contracts\Exception\RateLimited;
use Ritmo\Contracts\HttpResponse;
use Ritmo\Tests\Fakes\MovableClock;

final class CreditAndConfigTest extends TestCase
{
    public function testWithoutCreditHeadersNothingIsRefused(): void
    {
        $window = new CreditWindow(new MovableClock('2027-03-01T10:00:00Z'), 5.0);
        $window->note(new HttpResponse(200, ['X-Five-Min-Limit-Remaining' => '1']));

        $window->assertEnough();
        self::assertNull($window->remaining(), 'the credit left without the reset time is not trusted');
    }

    public function testLowCreditStopsUntilTheWindowResets(): void
    {
        $clock = new MovableClock('2027-03-01T10:00:00Z');
        $window = new CreditWindow($clock, 5.0);
        $window->note(new HttpResponse(200, ['X-Five-Min-Limit-Remaining' => '4.5', 'X-Five-Min-Limit-Resets-In' => '119.2', 'X-Request-Cost' => '1']));

        self::assertSame(4.5, $window->remaining());
        try {
            $window->assertEnough();
            self::fail('Credit below the minimum must stop the next request.');
        } catch (RateLimited $e) {
            self::assertSame(120, $e->retryAfterSeconds());
            self::assertNull($e->httpStatus());
            self::assertSame('beds24', $e->provider());
            self::assertSame('Beds24 call credit is low (4.5 left); no request was sent.', $e->getMessage());
        }

        $clock->advance(120);
        $window->assertEnough();
        self::assertNull($window->remaining(), 'a new window starts unknown');
    }

    public function testTheSpecSpellingAndTheCostOfTheLastRequestCount(): void
    {
        $window = new CreditWindow(new MovableClock('2027-03-01T10:00:00Z'), 5.0);
        $window->note(new HttpResponse(200, ['X-FiveMinCreditLimit-Remaining' => '8', 'X-FiveMinCreditLimit-ResetsIn' => '60', 'X-RequestCost' => '9']));

        $this->expectException(RateLimited::class);
        $window->assertEnough();
    }

    public function testAMinimumOfZeroTurnsTheCheckOff(): void
    {
        $window = new CreditWindow(new MovableClock('2027-03-01T10:00:00Z'), 0.0);
        $window->note(new HttpResponse(200, ['X-Five-Min-Limit-Remaining' => '0', 'X-Five-Min-Limit-Resets-In' => '200']));

        $window->assertEnough();
        self::assertSame(0.0, $window->remaining());
        self::assertSame(200, CreditWindow::resetsIn(new HttpResponse(429, ['x-five-min-limit-resets-in' => '199.01'])));
        self::assertNull(CreditWindow::resetsIn(new HttpResponse(429)));
    }

    public function testCredentialsNeverShowTheirSecret(): void
    {
        $refresh = Beds24Credentials::refreshToken('sim-refresh-secret-123');
        $longLife = Beds24Credentials::longLifeToken('sim-long-life-secret-456');

        self::assertTrue($refresh->isRefreshable());
        self::assertFalse($longLife->isRefreshable());
        self::assertSame('sim-refresh-secret-123', $refresh->secret());
        self::assertStringNotContainsString('secret-123', print_r($refresh, true));
        self::assertStringNotContainsString('secret-123', var_export($refresh->__debugInfo(), true));

        $this->expectException(LogicException::class);
        serialize($longLife);
    }

    public function testATokenWithSpacesOrNothingIsRefused(): void
    {
        foreach (['', 'two words', "tab\there"] as $token) {
            try {
                Beds24Credentials::longLifeToken($token);
                self::fail('Refused: ' . json_encode($token));
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testTheConfigurationNormalisesIdsAndChecksCodes(): void
    {
        $config = new Beds24Config(Beds24Credentials::longLifeToken('t'), [902001 => 'STD', ' 902002 ' => 'SUITE'], [901001, '901001', '901002']);

        self::assertSame(['902001' => 'STD', '902002' => 'SUITE'], $config->roomMap);
        self::assertSame(['901001', '901002'], $config->propertyIds);
        self::assertSame(Beds24Config::DEFAULT_MINIMUM_CREDIT, $config->minimumCredit);

        $invalid = [
            'room id not a number' => static fn () => new Beds24Config(Beds24Credentials::longLifeToken('t'), ['abc' => 'STD']),
            'room id zero' => static fn () => new Beds24Config(Beds24Credentials::longLifeToken('t'), [0 => 'STD']),
            'room code not a code' => static fn () => new Beds24Config(Beds24Credentials::longLifeToken('t'), [902001 => 'a room']),
            'property id negative' => static fn () => new Beds24Config(Beds24Credentials::longLifeToken('t'), [], [-5]),
            'negative credit' => static fn () => new Beds24Config(Beds24Credentials::longLifeToken('t'), [], [], null, -1.0),
        ];
        foreach ($invalid as $case => $build) {
            try {
                $build();
                self::fail('Refused: ' . $case);
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }
}
