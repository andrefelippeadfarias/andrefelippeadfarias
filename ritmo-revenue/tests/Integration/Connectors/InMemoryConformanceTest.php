<?php

declare(strict_types=1);

namespace Ritmo\Tests\Integration\Connectors;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Ritmo\Domain\Support\Days;
use Ritmo\Tests\Fakes\InMemoryPmsConnector;
use Ritmo\Tests\Fakes\InMemoryPricingConnector;

/**
 * The in-memory connectors of tests/Fakes answer like the real ones: filled with what the real
 * connectors read from the simulator, both give the same answer to the same narrower question.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class InMemoryConformanceTest extends SimulatorTestCase
{
    private const ROOMS = ['902001', '902002', '902003'];

    public function testThePmsFakeAnswersLikeBeds24(): void
    {
        $real = $this->beds24();
        $all = Days::date('2027-03-01');
        $last = Days::date('2027-04-29');
        $fake = new InMemoryPmsConnector($real->readBookingsByStay($all, $last), $real->readInventory(self::ROOMS, $all, $last, true));

        foreach ([['2027-03-05', '2027-03-05'], ['2027-03-12', '2027-03-14'], ['2027-04-05', '2027-04-20'], ['2027-04-25', '2027-04-29']] as [$from, $to]) {
            $range = [Days::date($from), Days::date($to)];
            self::assertEquals($real->readBookingsByStay(...$range), $fake->readBookingsByStay(...$range), "bookings $from..$to");
            self::assertEquals($real->readInventory(self::ROOMS, ...$range), $fake->readInventory(self::ROOMS, ...$range), "inventory $from..$to");
            self::assertEquals($real->readInventory(['902002'], $range[0], $range[1], true), $fake->readInventory(['902002'], $range[0], $range[1], true), "priced inventory $from..$to");
        }
        foreach (['2027-01-01T00:00:00Z', '2027-02-26T08:30:00Z', '2027-02-28T09:00:00Z', '2027-03-01T00:00:00Z'] as $since) {
            $instant = new \DateTimeImmutable($since);
            self::assertEquals($real->readBookingsModifiedSince($instant), $fake->readBookingsModifiedSince($instant), "modified since $since");
        }
        self::assertSame(['readBookingsByStay', 'readInventory', 'readInventory', 'readBookingsByStay'], array_slice($fake->calls(), 0, 4));
    }

    public function testThePricingFakeAnswersLikePriceLabs(): void
    {
        $real = $this->priceLabs();
        $all = Days::date('2027-03-01');
        $last = Days::date('2027-04-29');
        $listings = $real->listListings();
        $prices = [];
        $overrides = [];
        foreach ($listings as $listing) {
            $prices[$listing->ref] = $real->readPrices($listing->ref, $all, $last);
            $overrides[$listing->ref] = $real->readOverrides($listing->ref, $all, $last);
        }
        $fake = new InMemoryPricingConnector($listings, $prices, $overrides);

        self::assertEquals($listings, $fake->listListings());
        foreach ([['2027-03-05', '2027-03-05'], ['2027-03-19', '2027-03-27'], ['2027-04-01', '2027-04-29']] as [$from, $to]) {
            foreach ($listings as $listing) {
                $range = [Days::date($from), Days::date($to)];
                self::assertEquals($real->readPrices($listing->ref, ...$range), $fake->readPrices($listing->ref, ...$range), "prices {$listing->ref} $from..$to");
                self::assertEquals($real->readOverrides($listing->ref, ...$range), $fake->readOverrides($listing->ref, ...$range), "overrides {$listing->ref} $from..$to");
            }
        }
        $this->assertOnlyReadsReachedTheApis();
    }
}
