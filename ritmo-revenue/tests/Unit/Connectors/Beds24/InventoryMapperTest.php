<?php

declare(strict_types=1);

namespace Ritmo\Tests\Unit\Connectors\Beds24;

use PHPUnit\Framework\TestCase;
use Ritmo\Connectors\Beds24\InventoryMapper;
use Ritmo\Contracts\Exception\ConnectorException;
use Ritmo\Domain\Model\InventoryNight;
use Ritmo\Domain\Model\Restriction;
use Ritmo\Domain\Support\DateRange;

final class InventoryMapperTest extends TestCase
{
    /**
     * @param list<array<string, mixed>> $calendar
     *
     * @return array<string, array{int, ?int, ?int, string, ?float}>
     */
    private static function nights(array $calendar, string $from, string $to): array
    {
        $out = [];
        foreach ((new InventoryMapper())->nights($calendar, DateRange::fromStrings($from, $to)) as $night) {
            self::assertInstanceOf(InventoryNight::class, $night);
            $out[$night->date->format('Y-m-d')] = [$night->available, $night->minStay, $night->maxStay, $night->restriction->value, $night->pmsPrice];
        }

        return $out;
    }

    public function testRangesBecomeNightsInsideTheAskedRangeInOrder(): void
    {
        $nights = self::nights([
            ['from' => '2027-03-14', 'to' => '2027-03-20', 'numAvail' => 2, 'minStay' => 2, 'maxStay' => 14, 'override' => 'none'],
            ['from' => '2027-03-10', 'to' => '2027-03-13', 'numAvail' => '1', 'minStay' => 1, 'maxStay' => 30, 'override' => ''],
        ], '2027-03-12', '2027-03-15');

        self::assertSame([
            '2027-03-12' => [1, 1, 30, 'none', null],
            '2027-03-13' => [1, 1, 30, 'none', null],
            '2027-03-14' => [2, 2, 14, 'none', null],
            '2027-03-15' => [2, 2, 14, 'none', null],
        ], $nights);
    }

    public function testEveryOverrideIsMappedToTheRestrictionEnum(): void
    {
        $calendar = [];
        $day = 1;
        foreach (['none', 'blackout', 'noCheckIn', 'noCheckOut', 'noCheckInOrCheckOut', 'NOCHECKIN'] as $override) {
            $date = sprintf('2027-03-%02d', $day++);
            $calendar[] = ['from' => $date, 'to' => $date, 'numAvail' => 1, 'override' => $override];
        }

        self::assertSame(
            ['none', 'blackout', 'no_checkin', 'no_checkout', 'no_checkin_checkout', 'no_checkin'],
            array_column(array_values(self::nights($calendar, '2027-03-01', '2027-03-06')), 3)
        );
        self::assertFalse(Restriction::from('no_checkin_checkout')->allowsCheckout());
    }

    public function testAnUnknownOverrideIsAnError(): void
    {
        $this->expectException(ConnectorException::class);

        self::nights([['from' => '2027-03-01', 'to' => '2027-03-01', 'numAvail' => 1, 'override' => 'closedForever']], '2027-03-01', '2027-03-01');
    }

    public function testOverbookingSoldOutAndMissingValuesPassThroughAsTheyAre(): void
    {
        $nights = self::nights([
            ['from' => '2027-03-01', 'numAvail' => -1, 'minStay' => 0, 'maxStay' => null, 'override' => 'none'],
            ['from' => '2027-03-02', 'to' => '2027-03-02', 'numAvail' => 0],
            ['from' => '2027-03-03', 'to' => '2027-03-03', 'minStay' => 3],
        ], '2027-03-01', '2027-03-03');

        self::assertSame([
            '2027-03-01' => [-1, null, null, 'none', null],
            '2027-03-02' => [0, null, null, 'none', null],
        ], $nights, 'a range without "to" is one day; a day without numAvail is not reported');
    }

    public function testPricesAreReadWhenTheyCame(): void
    {
        $nights = self::nights([
            ['from' => '2027-03-01', 'to' => '2027-03-01', 'numAvail' => 1, 'price1' => '830.50'],
            ['from' => '2027-03-02', 'to' => '2027-03-02', 'numAvail' => 1, 'price1' => -1],
            ['from' => '2027-03-03', 'to' => '2027-03-03', 'numAvail' => 1, 'price1' => 0],
        ], '2027-03-01', '2027-03-03');

        self::assertSame([830.5, null, 0.0], array_column(array_values($nights), 4));
    }

    public function testALaterRangeReplacesAnEarlierOneForTheSameDay(): void
    {
        $nights = self::nights([
            ['from' => '2027-03-01', 'to' => '2027-03-03', 'numAvail' => 2],
            ['from' => '2027-03-02', 'to' => '2027-03-02', 'numAvail' => 0, 'override' => 'blackout'],
        ], '2027-03-01', '2027-03-03');

        self::assertSame([2, 0, 2], array_column(array_values($nights), 0));
        self::assertSame('blackout', $nights['2027-03-02'][3]);
    }

    public function testARangeWithoutValidDatesIsAnError(): void
    {
        foreach ([['to' => '2027-03-01'], ['from' => '2027-03-05', 'to' => '2027-03-01'], ['from' => 'soon']] as $span) {
            try {
                self::nights([$span + ['numAvail' => 1]], '2027-03-01', '2027-03-05');
                self::fail('A range without valid dates must be refused: ' . json_encode($span));
            } catch (ConnectorException $e) {
                self::assertSame('beds24', $e->provider());
            }
        }
    }
}
