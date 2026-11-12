<?php

declare(strict_types=1);

namespace Ritmo\Tests\Unit\Domain\Model;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionProperty;
use Ritmo\Domain\Model\AnchorOrigin;
use Ritmo\Domain\Model\BookingRecord;
use Ritmo\Domain\Model\BookingStatus;
use Ritmo\Domain\Model\DateOverride;
use Ritmo\Domain\Model\Identifier;
use Ritmo\Domain\Model\InventoryNight;
use Ritmo\Domain\Model\ListingInfo;
use Ritmo\Domain\Model\MarketAnchor;
use Ritmo\Domain\Model\NightType;
use Ritmo\Domain\Model\PaceStatus;
use Ritmo\Domain\Model\PriceNight;
use Ritmo\Domain\Model\PricePlan;
use Ritmo\Domain\Model\Property;
use Ritmo\Domain\Model\PropertyMode;
use Ritmo\Domain\Model\Restriction;
use Ritmo\Domain\Model\RoomType;
use Ritmo\Domain\Model\SyncStatus;
use Ritmo\Domain\Support\Days;

final class ModelTest extends TestCase
{
    public function testEnumValuesAreTheSharedVocabulary(): void
    {
        $values = static fn (array $cases): array => array_map(static fn ($case): string => $case->value, $cases);

        self::assertSame(['shadow', 'approval', 'auto'], $values(PropertyMode::cases()));
        self::assertSame(['weekday', 'weekend', 'holiday'], $values(NightType::cases()));
        self::assertSame(['sent', 'public', 'discounted'], $values(PricePlan::cases()));
        self::assertSame(['ahead', 'on_pace', 'behind', 'very_behind'], $values(PaceStatus::cases()));
        self::assertSame(['none', 'blackout', 'no_checkin', 'no_checkout', 'no_checkin_checkout'], $values(Restriction::cases()));
        self::assertSame(['measured', 'fallback'], $values(AnchorOrigin::cases()));
        self::assertSame(['confirmed', 'new', 'request', 'cancelled', 'blocked', 'inquiry'], $values(BookingStatus::cases()));
    }

    public function testEveryModelIsImmutable(): void
    {
        $classes = [
            Property::class, RoomType::class, InventoryNight::class, PriceNight::class, DateOverride::class,
            BookingRecord::class, SyncStatus::class, ListingInfo::class, MarketAnchor::class,
        ];
        foreach ($classes as $class) {
            $reflection = new ReflectionClass($class);
            self::assertTrue($reflection->isFinal(), "$class must be final");
            self::assertNotEmpty($reflection->getProperties());
            foreach ($reflection->getProperties() as $property) {
                self::assertTrue($property->isReadOnly(), "$class::\${$property->getName()} must be readonly");
            }
        }
    }

    public function testOnlyConfirmedAndNewBookingsOccupy(): void
    {
        $occupying = array_filter(BookingStatus::cases(), static fn (BookingStatus $status): bool => $status->occupies());

        self::assertSame([BookingStatus::Confirmed, BookingStatus::NewBooking], array_values($occupying));
    }

    public function testRestrictionsSayWhatTheyAllow(): void
    {
        self::assertTrue(Restriction::None->allowsCheckin() && Restriction::None->allowsCheckout());
        self::assertFalse(Restriction::NoCheckin->allowsCheckin());
        self::assertTrue(Restriction::NoCheckin->allowsCheckout());
        self::assertTrue(Restriction::NoCheckout->allowsCheckin());
        self::assertFalse(Restriction::NoCheckout->allowsCheckout());
        self::assertFalse(Restriction::NoCheckinCheckout->allowsCheckin() || Restriction::NoCheckinCheckout->allowsCheckout());
        self::assertFalse(Restriction::Blackout->allowsCheckin() || Restriction::Blackout->allowsCheckout());
    }

    public function testIdentifiers(): void
    {
        foreach (['main', 'Deluxe-2', 'K7', 'a_b', '7'] as $code) {
            self::assertTrue(Identifier::isCode($code), $code);
        }
        foreach (['', 'two words', '-lead', 'ção', str_repeat('x', 33), 'a.b', '*'] as $code) {
            self::assertFalse(Identifier::isCode($code), $code);
        }
        foreach (['standard', 'tub', 'sea_view2'] as $slug) {
            self::assertTrue(Identifier::isSlug($slug), $slug);
        }
        foreach (['', 'Tub', '2nd', 'sea-view', 'sea view'] as $slug) {
            self::assertFalse(Identifier::isSlug($slug), $slug);
        }
    }

    public function testPropertyDefaultsToShadowModeAndTheStandardTier(): void
    {
        $property = new Property('main', 'Main House', 'America/Sao_Paulo', 'BRL');

        self::assertSame(PropertyMode::Shadow, $property->mode);
        self::assertSame('standard', $property->tier);
        self::assertSame([], $property->occupancyGoals);
    }

    public function testPropertyArrayRoundTrip(): void
    {
        $property = new Property('main', 'Main House', 'Europe/Lisbon', 'EUR', PropertyMode::Auto, [7 => 75.0, 30 => 40.0], 'premium');

        self::assertEquals($property, Property::fromArray($property->toArray()));
        self::assertEquals($property, Property::fromArray(json_decode(json_encode($property->toArray(), JSON_THROW_ON_ERROR), true)));
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function invalidProperties(): array
    {
        $valid = ['id' => 'main', 'name' => 'Main House', 'timezone' => 'UTC', 'currency' => 'EUR'];

        return [
            'id with spaces' => [['id' => 'main house'] + $valid],
            'empty name' => [['name' => '  '] + $valid],
            'unknown timezone' => [['timezone' => 'Moon/Base'] + $valid],
            'lowercase currency' => [['currency' => 'eur'] + $valid],
            'unknown mode' => [['mode' => 'turbo'] + $valid],
            'mode that is not text' => [['mode' => ['auto']] + $valid],
            'tier that is not a slug' => [['tier' => 'Top Tier'] + $valid],
            'tier that is not text' => [['tier' => 3] + $valid],
            'goal above 100' => [['occupancy_goals' => [7 => 120]] + $valid],
            'goal for window zero' => [['occupancy_goals' => [0 => 50]] + $valid],
            'goal window that is not a number' => [['occupancy_goals' => ['soon' => 50]] + $valid],
            'goal that is not a number' => [['occupancy_goals' => [7 => 'high']] + $valid],
            'goals that are not a map' => [['occupancy_goals' => 70] + $valid],
            'missing id' => [array_diff_key($valid, ['id' => 1])],
            'name that is not text' => [['name' => 12] + $valid],
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    #[DataProvider('invalidProperties')]
    public function testPropertyRejectsInvalidData(array $data): void
    {
        $this->expectException(InvalidArgumentException::class);
        Property::fromArray($data);
    }

    public function testRoomTypeTagsAndArrayRoundTrip(): void
    {
        $room = new RoomType('main', 'Deluxe', 'Deluxe Suite', 3, 'r-9', 'l-9', 900.0, 600.0, 4000.0, 550.0, ['tub', 'view']);

        self::assertTrue($room->hasTag('tub'));
        self::assertFalse($room->hasTag('terrace'));
        self::assertTrue($room->hasAllTags([]));
        self::assertTrue($room->hasAllTags(['view', 'tub']));
        self::assertFalse($room->hasAllTags(['tub', 'terrace']));
        self::assertEquals($room, RoomType::fromArray($room->toArray()));
        self::assertEquals($room, RoomType::fromArray(json_decode(json_encode($room->toArray(), JSON_THROW_ON_ERROR), true)));
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function invalidRoomTypes(): array
    {
        $valid = ['property_id' => 'main', 'code' => 'Deluxe', 'name' => 'Deluxe', 'units' => 3, 'base' => 900, 'min' => 600, 'max' => 4000, 'reference_min' => 550];

        return [
            'code with a dot' => [['code' => 'De.luxe'] + $valid],
            'invalid property id' => [['property_id' => 'main house'] + $valid],
            'empty name' => [['name' => ''] + $valid],
            'no units' => [['units' => 0] + $valid],
            'units as a fraction' => [['units' => 2.5] + $valid],
            'base of zero' => [['base' => 0] + $valid],
            'negative minimum' => [['min' => -1] + $valid],
            'maximum below the minimum' => [['max' => 500] + $valid],
            'reference minimum of zero' => [['reference_min' => 0] + $valid],
            'price as text' => [['base' => '900'] + $valid],
            'tag that is not a slug' => [['tags' => ['Has Tub']] + $valid],
            'repeated tag' => [['tags' => ['tub', 'tub']] + $valid],
            'tags that are not a list' => [['tags' => 'tub'] + $valid],
            'reference that is not text' => [['pms_ref' => 12] + $valid],
            'missing code' => [array_diff_key($valid, ['code' => 1])],
            'missing base' => [array_diff_key($valid, ['base' => 1])],
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    #[DataProvider('invalidRoomTypes')]
    public function testRoomTypeRejectsInvalidData(array $data): void
    {
        $this->expectException(InvalidArgumentException::class);
        RoomType::fromArray($data);
    }

    public function testNightModelsKeepOnlyTheCalendarDate(): void
    {
        $evening = new DateTimeImmutable('2026-11-11 22:15', new DateTimeZone('America/Sao_Paulo'));

        $inventory = new InventoryNight($evening, 3, 2, null, Restriction::NoCheckin, 880.0);
        $price = new PriceNight($evening, 1200.0, 900.0, null, 2, 4, 7, true);
        $override = new DateOverride($evening, -31.0, null, 2, '1111011', '1111111', 'pace step');
        $anchor = new MarketAnchor($evening, 300.0, 400.0, 400.0, 650.0, 900.0, 18, $evening, AnchorOrigin::Measured);

        foreach ([$inventory->date, $price->date, $override->date, $anchor->date, $anchor->capturedAt] as $date) {
            self::assertSame('2026-11-11T00:00:00+00:00', $date->format(DATE_ATOM));
        }
        self::assertSame(Restriction::NoCheckin, $inventory->restriction);
        self::assertSame(880.0, $inventory->pmsPrice);
        self::assertTrue($price->hasDateOverride);
        self::assertSame(AnchorOrigin::Measured, $anchor->origin);
    }

    public function testInventoryNightDefaultsAndValidation(): void
    {
        $night = new InventoryNight(Days::date('2026-11-11'), 0);

        self::assertSame(Restriction::None, $night->restriction);
        self::assertNull($night->minStay);
        self::assertNull($night->pmsPrice);
        self::assertSame(-1, (new InventoryNight(Days::date('2026-11-11'), -1))->available, 'a negative count is an overbooked night');
        foreach ([[1, 0, null, null], [1, null, 0, null], [1, null, null, -5.0], [1, null, null, NAN]] as [$available, $minStay, $maxStay, $price]) {
            try {
                new InventoryNight(Days::date('2026-11-11'), $available, $minStay, $maxStay, Restriction::None, $price);
                self::fail('Expected an InvalidArgumentException.');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testPriceNightSoldOutAndValidation(): void
    {
        $date = Days::date('2026-11-11');

        self::assertFalse((new PriceNight($date, 900.0, null, null, null, 6, 7))->isSoldOut());
        self::assertTrue((new PriceNight($date, 900.0, null, null, null, 7, 7))->isSoldOut());
        self::assertTrue((new PriceNight($date, 900.0, null, null, null, 8, 7))->isSoldOut(), 'overbooked');
        self::assertFalse((new PriceNight($date, 900.0, null, null, null, 0, 0))->isSoldOut(), 'no units is not sold out');
        foreach ([[-1.0, null, 1, 1], [900.0, -2.0, 1, 1], [900.0, null, -1, 1], [900.0, null, 1, -1], [INF, null, 1, 1]] as [$price, $min, $sold, $total]) {
            try {
                new PriceNight($date, $price, $min, null, null, $sold, $total);
                self::fail('Expected an InvalidArgumentException.');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->expectException(InvalidArgumentException::class);
        new PriceNight($date, 900.0, null, null, 0, 1, 1);
    }

    public function testDateOverrideMasksPriceTypesAndPriceRule(): void
    {
        $date = Days::date('2026-11-13');

        self::assertTrue(DateOverride::isValidMask('1111011'));
        foreach (['111101', '11110111', '1111012', 'abcdefg', ''] as $mask) {
            self::assertFalse(DateOverride::isValidMask($mask), $mask);
        }
        self::assertFalse((new DateOverride($date, minStay: 2, checkoutMask: '1111011'))->hasPriceRule());
        self::assertTrue((new DateOverride($date, minPercent: -20.0))->hasPriceRule());
        self::assertTrue((new DateOverride($date, maxPercent: 15.0))->hasPriceRule());
        self::assertTrue((new DateOverride($date, price: 950.0, priceType: DateOverride::PRICE_FIXED))->hasPriceRule());
        self::assertTrue((new DateOverride($date, price: -10.0, priceType: DateOverride::PRICE_PERCENT))->hasPriceRule());
        self::assertTrue((new DateOverride($date, minFixed: 700.0))->hasPriceRule());
        self::assertTrue((new DateOverride($date, maxFixed: 1700.0))->hasPriceRule());

        $invalid = [
            static fn () => new DateOverride($date, checkinMask: '11110'),
            static fn () => new DateOverride($date, checkoutMask: '2222222'),
            static fn () => new DateOverride($date, minStay: 0),
            static fn () => new DateOverride($date, price: 900.0),
            static fn () => new DateOverride($date, priceType: DateOverride::PRICE_FIXED),
            static fn () => new DateOverride($date, price: 900.0, priceType: 'absolute'),
        ];
        foreach ($invalid as $build) {
            try {
                $build();
                self::fail('Expected an InvalidArgumentException.');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testBookingRecordHoldsNoGuestData(): void
    {
        $fields = array_map(
            static fn (ReflectionProperty $property): string => $property->getName(),
            (new ReflectionClass(BookingRecord::class))->getProperties()
        );
        sort($fields);

        self::assertSame(
            ['arrival', 'cancelledAt', 'channel', 'createdAt', 'departure', 'modifiedAt', 'origin', 'pmsRef', 'roomCode', 'roomPmsRef', 'status', 'total', 'units'],
            $fields,
            'BookingRecord must never grow a field for a guest name, contact or free text'
        );
    }

    public function testBookingRecordNightsAndValidation(): void
    {
        $created = new DateTimeImmutable('2026-11-01T14:03:00Z');
        $booking = new BookingRecord('B-1', 'Deluxe', Days::date('2026-12-30'), Days::date('2027-01-02'), BookingStatus::Confirmed, 'booking', 'Channel sync', 4200.0, $created, null, null, 'r-9', 2);

        self::assertSame(['2026-12-30', '2026-12-31', '2027-01-01'], $booking->nights()->keys());
        self::assertSame(2, $booking->units);
        self::assertSame('r-9', $booking->roomPmsRef);

        $unmapped = new BookingRecord('B-2', null, Days::date('2026-12-30'), Days::date('2026-12-31'), BookingStatus::Cancelled, 'direct', 'front desk', null, null, null, $created);
        self::assertNull($unmapped->roomCode);
        self::assertSame(1, $unmapped->units);

        $invalid = [
            static fn () => new BookingRecord('', 'Deluxe', Days::date('2026-12-30'), Days::date('2026-12-31'), BookingStatus::Confirmed, 'direct', '', null, null, null, null),
            static fn () => new BookingRecord('B-3', 'Deluxe', Days::date('2026-12-30'), Days::date('2026-12-30'), BookingStatus::Confirmed, 'direct', '', null, null, null, null),
            static fn () => new BookingRecord('B-4', 'Deluxe', Days::date('2026-12-30'), Days::date('2026-12-29'), BookingStatus::Confirmed, 'direct', '', null, null, null, null),
            static fn () => new BookingRecord('B-5', 'Deluxe', Days::date('2026-12-30'), Days::date('2026-12-31'), BookingStatus::Confirmed, 'direct', '', NAN, null, null, null),
            static fn () => new BookingRecord('B-6', 'Deluxe', Days::date('2026-12-30'), Days::date('2026-12-31'), BookingStatus::Confirmed, 'direct', '', null, null, null, null, '', 0),
        ];
        foreach ($invalid as $build) {
            try {
                $build();
                self::fail('Expected an InvalidArgumentException.');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testListingInfoAndSyncStatus(): void
    {
        $pushed = new DateTimeImmutable('2026-11-11T09:02:00Z');
        $listing = new ListingInfo('l-9', 'Deluxe Suite', 600.0, 900.0, 4000.0, 'EUR', new SyncStatus(true, $pushed));

        self::assertTrue($listing->sync->pushEnabled);
        self::assertSame($pushed, $listing->sync->lastPushedAt);
        self::assertNull($listing->sync->lastRefreshedAt);
        self::assertNull((new ListingInfo('l-10', 'New listing', null, null, null, null, new SyncStatus(false)))->base);

        $this->expectException(InvalidArgumentException::class);
        new ListingInfo('', 'No reference', null, null, null, null, new SyncStatus(false));
    }

    public function testMarketAnchorRequiresAscendingNonNegativePercentiles(): void
    {
        $date = Days::date('2026-11-13');
        $flat = new MarketAnchor($date, 500.0, 500.0, 500.0, 500.0, 500.0, 0, null, AnchorOrigin::Fallback);

        self::assertNull($flat->capturedAt);
        self::assertSame(AnchorOrigin::Fallback, $flat->origin);

        $invalid = [
            'p50 below p25' => [300.0, 250.0, 400.0, 500.0, 600.0, 5],
            'max below p90' => [300.0, 350.0, 400.0, 500.0, 450.0, 5],
            'negative' => [-1.0, 350.0, 400.0, 500.0, 600.0, 5],
            'not a number' => [300.0, NAN, 400.0, 500.0, 600.0, 5],
            'negative sample' => [300.0, 350.0, 400.0, 500.0, 600.0, -1],
        ];
        foreach ($invalid as $label => [$p25, $p50, $p75, $p90, $max, $n]) {
            try {
                new MarketAnchor($date, $p25, $p50, $p75, $p90, $max, $n, null, AnchorOrigin::Measured);
                self::fail("Expected an InvalidArgumentException for: $label");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testEveryFrozenMarketAnchorFitsTheModel(): void
    {
        $anchor = \Ritmo\Tests\Support\Fixtures::json('frozen/ancora_mercado.json');

        self::assertNotEmpty($anchor['noites']);
        foreach ($anchor['noites'] as $date => $row) {
            $model = new MarketAnchor(Days::date($date), $row['p25'], $row['p50'], $row['p75'], $row['p90'], $row['max'], $row['n'], Days::date($row['busca_em']), AnchorOrigin::Measured);
            self::assertSame($date, Days::key($model->date));
        }
    }
}
