<?php

declare(strict_types=1);

namespace Ritmo\Tests\Integration\Connectors;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Ritmo\Contracts\Exception\AuthFailed;
use Ritmo\Contracts\Exception\ConnectorException;
use Ritmo\Contracts\Exception\RateLimited;
use Ritmo\Contracts\Exception\WriteBlocked;
use Ritmo\Domain\Model\DateOverride;
use Ritmo\Domain\Model\ListingInfo;
use Ritmo\Domain\Model\PriceNight;
use Ritmo\Domain\Support\Days;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class PriceLabsSimulatorTest extends SimulatorTestCase
{
    /**
     * @param list<PriceNight> $nights
     */
    private static function night(array $nights, string $date): PriceNight
    {
        foreach ($nights as $night) {
            if ($night->date->format('Y-m-d') === $date) {
                return $night;
            }
        }
        self::fail('No night ' . $date);
    }

    public function testListingsPricesAndOverridesAreRead(): void
    {
        $connector = $this->priceLabs();

        $status = $connector->testConnection();
        $listings = $connector->listListings();
        $standard = $connector->readPrices('901001___902001', Days::date('2027-03-01'), Days::date('2027-04-29'));
        $master = $connector->readPrices('901001___902003', Days::date('2027-03-26'), Days::date('2027-04-08'));
        $overrides = [];
        foreach (['901001___902001', '901001___902002', '901001___902003'] as $ref) {
            $overrides[$ref] = array_map(
                static fn (DateOverride $o): array => [$o->date->format('Y-m-d'), $o->minPercent, $o->maxPercent, $o->minFixed, $o->maxFixed, $o->price, $o->priceType, $o->minStay, $o->checkinMask, $o->checkoutMask],
                $connector->readOverrides($ref, Days::date('2027-03-01'), Days::date('2027-04-29'))
            );
        }

        self::assertTrue($status->ok, $status->message);
        self::assertSame(['listings' => 3, 'account_listings' => 4, 'pms' => 'beds24'], $status->details);

        self::assertSame(['901001___902001', '901001___902002', '901001___902003'], array_map(static fn (ListingInfo $l): string => $l->ref, $listings));
        self::assertSame([320.0, 480.0, 1600.0, 'BRL'], [$listings[0]->min, $listings[0]->base, $listings[0]->max, $listings[0]->currency]);
        self::assertSame('2027-02-28T09:10:00+00:00', $listings[0]->sync->lastPushedAt?->format('c'));
        self::assertFalse($listings[2]->sync->pushEnabled);
        self::assertNull($listings[2]->sync->lastPushedAt);

        self::assertCount(60, $standard);
        $soldOut = self::night($standard, '2027-03-05');
        self::assertSame([600.0, 560.0, 4, 4, true], [$soldOut->price, $soldOut->uncustomizedPrice, $soldOut->sold, $soldOut->total, $soldOut->isSoldOut()]);
        self::assertSame(2, self::night($standard, '2027-03-13')->minStay, 'the minimum stay of the override');
        self::assertSame(610.0, self::night($standard, '2027-03-20')->price, 'the fixed price of the override');
        $booked = self::night($master, '2027-03-26');
        self::assertSame([1, 1, null, true], [$booked->sold, $booked->total, $booked->userPrice, $booked->isSoldOut()], 'a single unit: sold by booking status, user price -1');
        $blackout = self::night($master, '2027-04-06');
        self::assertSame([true, 0], [$blackout->unbookable, $blackout->sold]);

        self::assertSame([
            ['2027-03-05', 25.0, 80.0, null, null, null, null, null, null, null],
            ['2027-03-13', null, null, null, null, null, null, 2, '1111101', '1111111'],
            ['2027-03-20', null, null, null, null, 610.0, 'fixed', null, null, null],
            ['2027-03-27', -15.0, null, null, null, null, null, null, null, null],
        ], $overrides['901001___902001']);
        self::assertSame([
            ['2027-03-19', null, null, 900.0, 1900.0, null, null, 3, '1111011', '1111101'],
            ['2027-03-20', null, null, 900.0, 1900.0, null, null, 3, '1111011', '1111101'],
            ['2027-04-10', null, null, null, null, -10.0, 'percent', null, null, null],
        ], $overrides['901001___902002']);
        self::assertSame([['2027-04-02', 12.0, null, null, null, null, null, null, null, null]], $overrides['901001___902003']);

        self::assertSame([
            'GET /pricelabs/v1/listings',
            'GET /pricelabs/v1/listings',
            self::CALENDAR_READ,
            self::CALENDAR_READ,
            'GET /pricelabs/v1/listings/901001___902001/overrides',
            'GET /pricelabs/v1/listings/901001___902002/overrides',
            'GET /pricelabs/v1/listings/901001___902003/overrides',
        ], $this->server->lines());
        self::assertCount(7, $this->ledger->calls(), 'every request is counted in the hourly budget');
        $this->assertOnlyReadsReachedTheApis();
    }

    public function testWithoutTheAllowListTheCalendarPostNeverLeavesTheSite(): void
    {
        $http = $this->guardedHttp(false);
        $connector = $this->priceLabs($http);

        try {
            $connector->readPrices('901001___902001', Days::date('2027-03-01'), Days::date('2027-03-31'));
            self::fail('The guard must stop the POST.');
        } catch (WriteBlocked) {
            self::assertCount(1, $http->blockedAttempts());
        }
        self::assertCount(3, $connector->listListings(), 'reads by GET still work');

        self::assertSame(['GET /pricelabs/v1/listings'], $this->server->lines(), 'the POST did not reach the API');
    }

    public function testAnInvalidKeyIsAnAuthFailure(): void
    {
        $connector = $this->priceLabs(null, 'sim-forged-key');

        $status = $connector->testConnection();
        self::assertFalse($status->ok);
        self::assertSame(['http_status' => 403], $status->details);
        self::assertStringContainsString('API_KEY_INVALID', $status->message);

        try {
            $connector->readOverrides('901001___902001', Days::date('2027-03-01'), Days::date('2027-03-31'));
            self::fail('A forged key must end in AuthFailed.');
        } catch (AuthFailed $e) {
            self::assertSame(403, $e->httpStatus());
            self::assertStringNotContainsString('sim-forged-key', $e->getMessage());
        }
    }

    public function testA429AndAMissingListingAreTypedFailures(): void
    {
        $connector = $this->priceLabs();
        $this->server->fault('GET', '/pricelabs/v1/listings', 429, ['Retry-After' => '30']);

        try {
            $connector->listListings();
            self::fail('A 429 must end in RateLimited.');
        } catch (RateLimited $e) {
            self::assertSame([429, 30], [$e->httpStatus(), $e->retryAfterSeconds()]);
        }

        try {
            $connector->readPrices('901001___999999', Days::date('2027-03-01'), Days::date('2027-03-02'));
            self::fail('A listing the account does not have has no calendar.');
        } catch (ConnectorException $e) {
            self::assertSame('PriceLabs has no calendar for the listing (LISTING_NOT_PRESENT).', $e->getMessage());
        }

        self::assertSame([429, 200], array_column($this->server->requests(), 'status'));
        self::assertSame([false, true], array_column($this->ledger->calls(), 'succeeded'));
    }
}
