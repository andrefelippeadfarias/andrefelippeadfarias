<?php

declare(strict_types=1);

namespace Ritmo\Tests\Unit\Connectors\PriceLabs;

use PHPUnit\Framework\TestCase;
use Ritmo\Connectors\PriceLabs\ListingMapper;
use Ritmo\Connectors\PriceLabs\OverrideMapper;
use Ritmo\Connectors\PriceLabs\PriceNightMapper;
use Ritmo\Domain\Model\DateOverride;

final class MappersTest extends TestCase
{
    public function testAListingIsMappedWithItsSyncState(): void
    {
        $listing = (new ListingMapper())->map([
            'id' => '901001___902001', 'pms' => 'beds24', 'name' => 'Standard Room', 'min' => '320', 'base' => 480, 'max' => 1600.5,
            'currency' => 'brl', 'push_enabled' => true, 'last_date_pushed' => '2027-02-28T09:10:00Z', 'last_refreshed_at' => '2027-02-28 21:30:00',
        ]);

        self::assertNotNull($listing);
        self::assertSame(['901001___902001', 'Standard Room', 320.0, 480.0, 1600.5, 'BRL'], [$listing->ref, $listing->name, $listing->min, $listing->base, $listing->max, $listing->currency]);
        self::assertTrue($listing->sync->pushEnabled);
        self::assertSame('2027-02-28T09:10:00+00:00', $listing->sync->lastPushedAt?->format('c'));
        self::assertSame('2027-02-28T21:30:00+00:00', $listing->sync->lastRefreshedAt?->format('c'));
        self::assertSame('beds24', ListingMapper::pmsOf(['pms' => ' Beds24 ']));
    }

    public function testMissingListingValuesStayUnknown(): void
    {
        $mapper = new ListingMapper();
        $listing = $mapper->map(['id' => 77, 'min' => -1, 'base' => 0, 'currency' => 'reais', 'push_enabled' => 'false', 'last_date_pushed' => null]);

        self::assertNotNull($listing);
        self::assertSame(['77', '', null, null, null, null], [$listing->ref, $listing->name, $listing->min, $listing->base, $listing->max, $listing->currency]);
        self::assertFalse($listing->sync->pushEnabled);
        self::assertNull($listing->sync->lastPushedAt);
        self::assertNull($mapper->map(['name' => 'no id']));
    }

    public function testANightOfAListingWithSeveralUnits(): void
    {
        $night = (new PriceNightMapper())->map([
            'date' => '2027-03-05', 'price' => 600, 'user_price' => '600', 'uncustomized_price' => 560, 'min_price' => 320, 'max_price' => 1600,
            'min_stay' => 2, 'booking_status' => 'Booked', 'multi_unit_occupancy' => ' 4 / 4 ', 'unbookable' => 0,
        ]);

        self::assertNotNull($night);
        self::assertSame('2027-03-05', $night->date->format('Y-m-d'));
        self::assertSame([600.0, 600.0, 560.0, 320.0, 1600.0, 2], [$night->price, $night->userPrice, $night->uncustomizedPrice, $night->minPrice, $night->maxPrice, $night->minStay]);
        self::assertSame([4, 4], [$night->sold, $night->total]);
        self::assertTrue($night->isSoldOut());
        self::assertFalse($night->unbookable);
        self::assertFalse($night->hasDateOverride, 'the calendar does not say; readOverrides() does');
    }

    public function testANightOfASingleUnitIsSoldByItsBookingStatus(): void
    {
        $mapper = new PriceNightMapper();
        $booked = $mapper->map(['date' => '2027-03-26', 'price' => 1360, 'user_price' => -1, 'booking_status' => 'Booked', 'min_stay' => 0, 'unbookable' => '1']);
        $free = $mapper->map(['date' => '2027-03-30', 'price' => '980.40', 'user_price' => 980.4, 'booking_status' => '']);

        self::assertNotNull($booked);
        self::assertNotNull($free);
        self::assertSame([1, 1, null, null], [$booked->sold, $booked->total, $booked->userPrice, $booked->minStay], '-1 and 0 mean "no value"');
        self::assertTrue($booked->unbookable);
        self::assertSame([0, 1, 980.4], [$free->sold, $free->total, $free->price]);
        self::assertFalse($free->isSoldOut());
    }

    public function testANightWithoutADateOrAPriceIsUnreadable(): void
    {
        $mapper = new PriceNightMapper();

        self::assertNull($mapper->map(['price' => 100]));
        self::assertNull($mapper->map(['date' => '2027-03-05']));
        self::assertNull($mapper->map(['date' => '2027-03-05', 'price' => -1]));
        self::assertNotNull($mapper->map(['date' => '2027-03-05', 'price' => 0]));
    }

    public function testAnOverrideWithFloorAndCeilingOverTheBase(): void
    {
        $override = (new OverrideMapper())->map([
            'date' => '2027-03-05', 'price' => null, 'price_type' => null, 'min_price' => '25', 'min_price_type' => 'percent_base',
            'max_price' => '80', 'max_price_type' => 'PERCENT_BASE', 'min_stay' => null, 'check_in' => null, 'check_out' => null,
            'currency' => 'BRL', 'reason' => 'Sold-out night protection', 'lead_time_expiry' => null,
        ]);

        self::assertNotNull($override);
        self::assertSame([25.0, 80.0, null, null, null, null], [$override->minPercent, $override->maxPercent, $override->minFixed, $override->maxFixed, $override->price, $override->priceType]);
        self::assertSame('Sold-out night protection', $override->reason);
        self::assertNull($override->minStay);
        self::assertNull($override->checkinMask);
        self::assertTrue($override->hasPriceRule(), 'a floor or a ceiling is a price rule');
    }

    public function testAnOverrideWithFixedBoundsMasksAndMinimumStay(): void
    {
        $override = (new OverrideMapper())->map([
            'date' => '2027-03-19', 'min_stay' => '3', 'check_in' => '1111011', 'check_out' => '1111101', 'check_in_check_out_enabled' => '1',
            'min_price' => '900', 'min_price_type' => 'fixed', 'max_price' => '1900', 'max_price_type' => 'fixed', 'reason' => "Long\nweekend",
        ]);

        self::assertNotNull($override);
        self::assertSame([null, null, 900.0, 1900.0, 3], [$override->minPercent, $override->maxPercent, $override->minFixed, $override->maxFixed, $override->minStay]);
        self::assertSame(['1111011', '1111101'], [$override->checkinMask, $override->checkoutMask]);
        self::assertSame('Long weekend', $override->reason);
    }

    public function testTheLeadTimeExpiryIsReadAsWholeDaysOnly(): void
    {
        $mapper = new OverrideMapper();

        self::assertSame(3, $mapper->map(['date' => '2027-03-11', 'min_price' => '10', 'lead_time_expiry' => 3])?->leadTimeExpiryDays);
        self::assertSame(2, $mapper->map(['date' => '2027-03-11', 'lead_time_expiry' => '2'])?->leadTimeExpiryDays);
        self::assertNull($mapper->map(['date' => '2027-03-11', 'lead_time_expiry' => '2.5'])?->leadTimeExpiryDays, 'not a whole number of days: read as none');
        self::assertNull($mapper->map(['date' => '2027-03-11'])?->leadTimeExpiryDays);
    }

    public function testPriceRulesAndWhatCannotBeRead(): void
    {
        $mapper = new OverrideMapper();
        $fixed = $mapper->map(['date' => '2027-03-20', 'price' => '610', 'price_type' => 'fixed']);
        $percent = $mapper->map(['date' => '2027-04-10', 'price' => '-10', 'price_type' => 'percent']);
        $untyped = $mapper->map(['date' => '2027-04-11', 'price' => '50']);
        $strange = $mapper->map(['date' => '2027-04-12', 'min_price' => '5', 'min_price_type' => 'per_guest', 'check_in' => '1111111', 'check_out' => '0000000', 'check_in_check_out_enabled' => '0']);
        $badMask = $mapper->map(['date' => '2027-04-13', 'check_in' => '11111', 'check_out' => 'yyyyyyy']);

        self::assertSame([610.0, DateOverride::PRICE_FIXED], [$fixed?->price, $fixed?->priceType]);
        self::assertSame([-10.0, DateOverride::PRICE_PERCENT], [$percent?->price, $percent?->priceType]);
        self::assertSame([null, null], [$untyped?->price, $untyped?->priceType], 'a price without its type cannot be read');
        self::assertSame([null, null], [$strange?->minPercent, $strange?->minFixed], 'a bound of an unknown type is left out');
        self::assertSame([null, null], [$strange?->checkinMask, $strange?->checkoutMask], 'masks that are switched off are not in force');
        self::assertSame([null, null], [$badMask?->checkinMask, $badMask?->checkoutMask]);
        self::assertNull($mapper->map(['date' => 'tomorrow', 'min_price' => '10']));
    }

    public function testAFloorWithoutATypeIsAPercentageOverTheBase(): void
    {
        $override = (new OverrideMapper())->map(['date' => '2027-04-02', 'min_price' => '12']);

        self::assertSame([12.0, null], [$override?->minPercent, $override?->minFixed]);
    }
}
