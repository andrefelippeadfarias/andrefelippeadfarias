<?php

declare(strict_types=1);

namespace Ritmo\Tests\Unit\Connectors\Fakes;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Ritmo\Contracts\Exception\ConnectorException;
use Ritmo\Contracts\Exception\NotAvailableInThisMilestone;
use Ritmo\Contracts\WriteToken;
use Ritmo\Domain\Model\BookingRecord;
use Ritmo\Domain\Model\BookingStatus;
use Ritmo\Domain\Model\DateOverride;
use Ritmo\Domain\Model\InventoryNight;
use Ritmo\Domain\Model\ListingInfo;
use Ritmo\Domain\Model\PriceNight;
use Ritmo\Domain\Model\Restriction;
use Ritmo\Domain\Model\SyncStatus;
use Ritmo\Domain\Support\Days;
use Ritmo\Tests\Fakes\InMemoryPmsConnector;
use Ritmo\Tests\Fakes\InMemoryPricingConnector;

final class InMemoryConnectorsTest extends TestCase
{
    public function testThePmsFakeFiltersSortsAndDropsPricesUnlessAsked(): void
    {
        $stay = new BookingRecord('1', 'STD', Days::date('2027-03-05'), Days::date('2027-03-07'), BookingStatus::Cancelled, 'direct', '', null, null, null, null, '902001');
        $pms = new InMemoryPmsConnector([$stay], ['902001' => [
            new InventoryNight(Days::date('2027-03-06'), 1, null, null, Restriction::None, 500.0),
            new InventoryNight(Days::date('2027-03-05'), 0, 2, null, Restriction::NoCheckin, 480.0),
        ]]);

        self::assertSame([$stay], $pms->readBookingsByStay(Days::date('2027-03-06'), Days::date('2027-03-10')));
        self::assertSame([], $pms->readBookingsByStay(Days::date('2027-03-07'), Days::date('2027-03-10')), 'the departure day is not a night');
        self::assertSame([$stay], $pms->readBookingsModifiedSince(Days::date('2027-03-01')), 'no timestamp: kept');

        $plain = $pms->readInventory(['902001', 902001, '902099'], Days::date('2027-03-01'), Days::date('2027-03-31'));
        self::assertSame(['2027-03-05', '2027-03-06'], array_map(static fn (InventoryNight $n): string => $n->date->format('Y-m-d'), $plain['902001']));
        self::assertNull($plain['902001'][0]->pmsPrice);
        self::assertSame([], $plain['902099']);
        self::assertSame(480.0, $pms->readInventory(['902001'], Days::date('2027-03-05'), Days::date('2027-03-05'), true)['902001'][0]->pmsPrice);

        self::assertTrue($pms->testConnection()->ok);
        self::assertSame(['readBookingsByStay', 'readBookingsByStay', 'readBookingsModifiedSince', 'readInventory', 'readInventory', 'testConnection'], $pms->calls());
    }

    public function testThePricingFakeKnowsItsListingsOnly(): void
    {
        $listing = new ListingInfo('L1', 'Room', 100.0, 200.0, 900.0, 'BRL', new SyncStatus(true));
        $pricing = new InMemoryPricingConnector(
            [$listing],
            ['L1' => [new PriceNight(Days::date('2027-03-02'), 210.0, null, null, null, 0, 1), new PriceNight(Days::date('2027-03-01'), 200.0, null, null, null, 1, 1)]],
            ['L2' => [new DateOverride(Days::date('2027-03-01'), 10.0)]]
        );

        self::assertSame([$listing], $pricing->listListings());
        self::assertSame(['2027-03-01', '2027-03-02'], array_map(static fn (PriceNight $n): string => $n->date->format('Y-m-d'), $pricing->readPrices('L1', Days::date('2027-03-01'), Days::date('2027-03-05'))));
        self::assertSame([], $pricing->readOverrides('L1', Days::date('2027-03-01'), Days::date('2027-03-05')));
        self::assertCount(1, $pricing->readOverrides('L2', Days::date('2027-03-01'), Days::date('2027-03-01')));

        $this->expectException(ConnectorException::class);
        $pricing->readPrices('L3', Days::date('2027-03-01'), Days::date('2027-03-05'));
    }

    public function testNeitherFakeCanWrite(): void
    {
        $token = (new ReflectionClass(WriteToken::class))->newInstanceWithoutConstructor();
        $pricing = new InMemoryPricingConnector();
        $writes = [
            static fn () => (new InMemoryPmsConnector())->writeCalendar($token, []),
            static fn () => $pricing->writeOverrides($token, 'L1', []),
            static fn () => $pricing->deleteOverrides($token, 'L1', []),
            static fn () => $pricing->updateListing($token, 'L1', []),
            static fn () => $pricing->requestRecalculation($token, 'L1'),
        ];

        foreach ($writes as $write) {
            try {
                $write();
                self::fail('A fake must not write either.');
            } catch (NotAvailableInThisMilestone) {
                self::addToAssertionCount(1);
            }
        }
    }
}
