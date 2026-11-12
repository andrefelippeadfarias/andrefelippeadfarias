<?php

declare(strict_types=1);

namespace Ritmo\Tests\Integration\Connectors;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Ritmo\Connectors\Beds24\Beds24Credentials;
use Ritmo\Contracts\Exception\AuthFailed;
use Ritmo\Contracts\Exception\RateLimited;
use Ritmo\Domain\Model\BookingRecord;
use Ritmo\Domain\Model\InventoryNight;
use Ritmo\Domain\Support\Days;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class Beds24SimulatorTest extends SimulatorTestCase
{
    /**
     * @param list<InventoryNight> $nights
     */
    private static function night(array $nights, string $date): InventoryNight
    {
        foreach ($nights as $night) {
            if ($night->date->format('Y-m-d') === $date) {
                return $night;
            }
        }
        self::fail('No night ' . $date);
    }

    public function testBookingsOfEveryStatusAreReadAcrossPagesWithoutGuestData(): void
    {
        $connector = $this->beds24();

        $status = $connector->testConnection();
        $bookings = $connector->readBookingsByStay(Days::date('2027-03-01'), Days::date('2027-04-29'));

        self::assertTrue($status->ok, $status->message);
        self::assertSame(['properties' => 1, 'property_names' => 'Example Inn'], $status->details);

        $summary = [];
        foreach ($bookings as $booking) {
            self::assertInstanceOf(BookingRecord::class, $booking);
            $summary[$booking->pmsRef] = [$booking->status->value, $booking->roomCode, $booking->total, $booking->units, $booking->channel];
        }
        self::assertSame([
            '7001' => ['confirmed', 'STD', 2240.0, 2, 'booking'],
            '7002' => ['confirmed', 'STD', 560.0, 1, 'airbnb'],
            '7003' => ['new', 'STD', 1540.0, 1, 'direct'],
            '7004' => ['cancelled', 'STD', 1120.0, 1, 'booking'],
            '7005' => ['request', 'STD', 1120.0, 1, 'booking'],
            '7006' => ['confirmed', 'GDN', 1640.0, 1, 'booking'],
            '7007' => ['confirmed', 'GDN', 1460.0, 1, 'airbnb'],
            '7008' => ['confirmed', 'GDN', 820.0, 1, 'direct'],
            '7009' => ['inquiry', 'GDN', null, 1, 'booking'],
            '7010' => ['confirmed', null, 3680.0, 1, 'booking'],
            '7011' => ['blocked', null, null, 1, 'direct'],
            '7012' => ['confirmed', null, 2700.0, 1, 'expedia'],
        ], $summary);
        self::assertSame('902003', $bookings[9]->roomPmsRef);
        self::assertSame('2027-02-26T08:30:00+00:00', $bookings[3]->cancelledAt?->format('c'));

        $dump = print_r($bookings, true);
        self::assertStringNotContainsString('GUESTDATA', $dump);
        self::assertStringNotContainsString('example.invalid', $dump);

        self::assertSame(['GET /beds24/v2/properties', 'GET /beds24/v2/bookings', 'GET /beds24/v2/bookings', 'GET /beds24/v2/bookings'], $this->server->lines());
        $queries = array_column($this->server->requests(), 'query');
        self::assertStringContainsString('status=confirmed&status=new&status=request&status=cancelled&status=black&status=inquiry', $queries[1]);
        self::assertStringEndsWith('page=3', $queries[3]);
        $this->assertOnlyReadsReachedTheApis();
    }

    public function testTheInventoryShowsSoldOutOverbookedAndRestrictedNights(): void
    {
        $inventory = $this->beds24()->readInventory(['902001', '902002', '902003'], Days::date('2027-03-01'), Days::date('2027-04-29'), true);

        foreach (['902001', '902002', '902003'] as $room) {
            self::assertCount(60, $inventory[$room], $room);
        }

        $soldOut = self::night($inventory['902001'], '2027-03-05');
        self::assertSame([0, 600.0], [$soldOut->available, $soldOut->pmsPrice], 'sold out: 4 of 4; the price is held at the floor of its override');
        self::assertSame(-1, self::night($inventory['902002'], '2027-03-13')->available, 'overbooked: 3 bookings on 2 units');
        self::assertSame(4, self::night($inventory['902001'], '2027-03-02')->available);

        self::assertSame('no_checkin', self::night($inventory['902001'], '2027-03-13')->restriction->value);
        $noCheckout = self::night($inventory['902001'], '2027-03-28');
        self::assertSame(['no_checkout', 1, 5], [$noCheckout->restriction->value, $noCheckout->minStay, $noCheckout->maxStay]);
        $closed = self::night($inventory['902002'], '2027-03-20');
        self::assertSame(['no_checkin_checkout', 3, 14], [$closed->restriction->value, $closed->minStay, $closed->maxStay]);
        $blackout = self::night($inventory['902003'], '2027-04-06');
        self::assertSame(['blackout', 0], [$blackout->restriction->value, $blackout->available]);
        self::assertSame(1360.0, self::night($inventory['902003'], '2027-03-26')->pmsPrice);

        self::assertSame(['GET /beds24/v2/inventory/rooms/calendar'], $this->server->lines());
        self::assertStringContainsString('roomId=902001&roomId=902002&roomId=902003', $this->server->requests()[0]['query']);
        self::assertStringContainsString('includePrices=true', $this->server->requests()[0]['query']);
    }

    public function testARevokedAccessTokenIsRenewedOnceFromTheRefreshToken(): void
    {
        $connector = $this->beds24(Beds24Credentials::refreshToken(self::BEDS24_REFRESH));

        self::assertTrue($connector->testConnection()->ok);
        $this->server->revokeAccessTokens();
        $changed = $connector->readBookingsModifiedSince(new \DateTimeImmutable('2027-02-26T21:00:00-03:00'));

        self::assertSame(['7005', '7008', '7009', '7012'], array_map(static fn (BookingRecord $b): string => $b->pmsRef, $changed));
        self::assertSame(
            ['GET /beds24/v2/authentication/token', 'GET /beds24/v2/properties', 'GET /beds24/v2/bookings', 'GET /beds24/v2/authentication/token', 'GET /beds24/v2/bookings'],
            $this->server->lines()
        );
        self::assertSame([200, 200, 401, 200, 200], array_column($this->server->requests(), 'status'));
        self::assertStringContainsString('modifiedFrom=2027-02-27T00%3A00%3A00Z', $this->server->requests()[2]['query']);
    }

    public function testARejectedLongLifeTokenIsAnAuthFailureWithoutRetry(): void
    {
        $connector = $this->beds24(Beds24Credentials::longLifeToken('sim-forged-token'));

        $status = $connector->testConnection();
        self::assertFalse($status->ok);
        self::assertSame(['http_status' => 401], $status->details);

        $this->expectException(AuthFailed::class);
        try {
            $connector->readBookingsByStay(Days::date('2027-03-01'), Days::date('2027-03-31'));
        } finally {
            self::assertSame([401, 401], array_column($this->server->requests(), 'status'));
        }
    }

    public function testA429AndLowCreditStopTheConnectorWithTypedExceptions(): void
    {
        $connector = $this->beds24();
        $this->server->fault('GET', '/beds24/v2/bookings', 429, ['X-Five-Min-Limit-Remaining' => '-2', 'X-Five-Min-Limit-Resets-In' => '45', 'X-Request-Cost' => '1']);

        try {
            $connector->readBookingsByStay(Days::date('2027-03-01'), Days::date('2027-03-31'));
            self::fail('A 429 must end in RateLimited.');
        } catch (RateLimited $e) {
            self::assertSame([429, 45], [$e->httpStatus(), $e->retryAfterSeconds()]);
        }

        // The 429 left the credit negative for 45 seconds: nothing more is sent until then.
        $status = $connector->testConnection();
        self::assertFalse($status->ok);
        self::assertStringContainsString('call credit is low', $status->message);
        try {
            $connector->readInventory(['902001'], Days::date('2027-03-01'), Days::date('2027-03-02'));
            self::fail('Low credit must stop the request.');
        } catch (RateLimited $e) {
            self::assertNull($e->httpStatus());
        }
        self::assertCount(1, $this->server->requests(), 'only the request that got the 429 reached the API');

        $this->clock->advance(45);
        $this->server->fault('GET', '/beds24/v2/properties', 0, ['X-Five-Min-Limit-Remaining' => '2']);
        self::assertTrue($connector->testConnection()->ok);
        try {
            $connector->readInventory(['902001'], Days::date('2027-03-01'), Days::date('2027-03-02'));
            self::fail('Credit of 2 is below the minimum of 5.');
        } catch (RateLimited $e) {
            self::assertSame(280, $e->retryAfterSeconds());
        }
        self::assertSame(['GET /beds24/v2/bookings', 'GET /beds24/v2/properties'], $this->server->lines());
    }
}
