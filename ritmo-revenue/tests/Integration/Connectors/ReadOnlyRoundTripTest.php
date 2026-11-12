<?php

declare(strict_types=1);

namespace Ritmo\Tests\Integration\Connectors;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use ReflectionClass;
use Ritmo\Connectors\Beds24\Beds24Credentials;
use Ritmo\Contracts\Exception\NotAvailableInThisMilestone;
use Ritmo\Contracts\Exception\WriteBlocked;
use Ritmo\Contracts\WriteToken;
use Ritmo\Domain\Support\Days;

/**
 * Every read of both connectors against one simulator, every write attempted, and then the
 * simulator's own log: only GET and the calendar read of the pricing system arrived.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class ReadOnlyRoundTripTest extends SimulatorTestCase
{
    public function testAFullReadOfBothSystemsSendsNoWrite(): void
    {
        $http = $this->guardedHttp(true);
        $pms = $this->beds24(Beds24Credentials::refreshToken(self::BEDS24_REFRESH), $http);
        $pricing = $this->priceLabs($http);
        $from = Days::date('2027-03-01');
        $to = Days::date('2027-04-29');

        self::assertTrue($pms->testConnection()->ok);
        self::assertTrue($pricing->testConnection()->ok);
        self::assertCount(12, $pms->readBookingsByStay($from, $to));
        self::assertCount(12, $pms->readBookingsModifiedSince(Days::date('2027-01-01')));
        self::assertCount(3, $pms->readInventory(array_keys(self::ROOM_MAP) + [2 => '902003'], $from, $to, true));
        foreach ($pricing->listListings() as $listing) {
            self::assertCount(60, $pricing->readPrices($listing->ref, $from, $to));
            $pricing->readOverrides($listing->ref, $from, $to);
        }

        $token = (new ReflectionClass(WriteToken::class))->newInstanceWithoutConstructor();
        $writes = [
            static fn () => $pms->writeCalendar($token, [['room' => '902001', 'from' => '2027-03-05', 'to' => '2027-03-05', 'minStay' => 3]]),
            static fn () => $pricing->writeOverrides($token, '901001___902001', [['date' => '2027-03-05', 'min_price' => '30']]),
            static fn () => $pricing->deleteOverrides($token, '901001___902001', ['2027-03-05']),
            static fn () => $pricing->updateListing($token, '901001___902001', ['min' => 300]),
            static fn () => $pricing->requestRecalculation($token, '901001___902001'),
        ];
        foreach ($writes as $write) {
            try {
                $write();
                self::fail('No write is available in this milestone.');
            } catch (NotAvailableInThisMilestone) {
                self::addToAssertionCount(1);
            }
        }

        // Even a hand-made write through the same client is stopped before it leaves.
        foreach (['POST' => '/beds24/v2/inventory/rooms/calendar', 'DELETE' => '/pricelabs/v1/listings/901001___902001/overrides'] as $method => $path) {
            try {
                $http->request($method, $this->server->url($path), ['Content-Type' => 'application/json'], '{}');
                self::fail('The guard must stop ' . $method);
            } catch (WriteBlocked) {
                self::addToAssertionCount(1);
            }
        }

        $lines = $this->server->lines();
        self::assertCount(1 + 2 + 3 + 3 + 1 + 1 + 3 + 3, $lines, 'token, connection tests, 3 pages twice, calendar, directory, 3 calendars, 3 override reads');
        self::assertSame(3, count(array_keys($lines, self::CALENDAR_READ, true)));
        $this->assertOnlyReadsReachedTheApis();
        self::assertSame([], array_values(array_filter($this->server->requests(), static fn (array $r): bool => $r['status'] >= 400)));
    }
}
