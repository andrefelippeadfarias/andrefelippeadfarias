<?php

declare(strict_types=1);

namespace Ritmo\Tests\Unit\Domain\Stay\Write;

use PHPUnit\Framework\TestCase;
use Ritmo\Domain\Calendar\Stay\PricingStayNight;
use Ritmo\Domain\Calendar\Stay\PricingSync;
use Ritmo\Domain\Calendar\Stay\StaySyncCapability;
use Ritmo\Domain\Calendar\Stay\StaySyncField;
use Ritmo\Domain\Config\InvalidConfig;
use Ritmo\Domain\Model\DateOverride;
use Ritmo\Domain\Model\Restriction;
use Ritmo\Domain\Plan\PlanItem;
use Ritmo\Domain\Stay\Write\MergeResult;
use Ritmo\Domain\Stay\Write\PmsChangeEntry;
use Ritmo\Domain\Stay\Write\PushedPmsValues;
use Ritmo\Domain\Stay\Write\RevertLedger;
use Ritmo\Domain\Stay\Write\StayItemFactory;
use Ritmo\Domain\Stay\Write\StayItemMerger;
use Ritmo\Domain\Stay\Write\StayPairing;
use Ritmo\Domain\Stay\Write\StayWriteConfig;
use Ritmo\Domain\Support\Days;
use Ritmo\Domain\Write\State\ChangeField;
use Ritmo\Domain\Write\State\NightFields;

/**
 * The merger, the pushed values (D6), the pairing, the revert ledger and the configuration.
 */
final class StayWritePiecesTest extends TestCase
{
    private const NIGHT = '2026-12-04'; // a Friday: weekday 4

    public function testTheStayValueWinsAConflictAndFillsWhatThePriceItemLeaves(): void
    {
        $night = Days::date(self::NIGHT);
        $price = new PlanItem('STD', $night, 500.0, minStay: 3, reasonCode: 'pace_step');
        $stay = new PlanItem('STD', $night, minStay: 2, reasonCode: 'STAY_ARRIVAL_DAY', checkInMask: '1111011');
        $other = new PlanItem('GDN', $night, minStay: 1, reasonCode: 'STAY_ORPHAN_NIGHT');
        $merged = StayItemMerger::merge([$price], [$stay, $other]);
        self::assertSame(['STD|' . self::NIGHT], $merged->conflicts);
        $item = $merged->item('STD|' . self::NIGHT);
        self::assertSame([500.0, 2, '1111011', 'pace_step'], [$item->minPrice, $item->minStay, $item->checkInMask, $item->reasonCode]);
        self::assertSame('STAY_ARRIVAL_DAY', $item->reasonParams['stay_reason']);
        self::assertCount(2, $merged->items);
        self::assertSame([], StayItemMerger::merge([$price], [new PlanItem('STD', $night, checkOutMask: '1111110')])->conflicts);
    }

    public function testWhatThePushSetsFollowsTheRoomsAndTheMasksInForce(): void
    {
        $pushed = new PushedPmsValues(new PricingSync(['*'], [], ['STD']), new StaySyncCapability(StaySyncField::MinStay, StaySyncField::ArrivalDeparture));
        $night = Days::date(self::NIGHT);
        $calendar = new PricingStayNight($night, 2, 0, 4, '1111111', '1111111');
        $closingArrival = new DateOverride($night, checkinMask: '1111011', checkoutMask: '1111111');
        self::assertSame([ChangeField::PMS_MIN_STAY => 2, ChangeField::PMS_RESTRICTION => 'no_checkin'], $pushed->of('STD', $night, $calendar, $closingArrival)->all(), 'the date rule closes Friday arrivals: no third-party change');
        self::assertSame([ChangeField::PMS_MIN_STAY => 2], $pushed->of('GDN', $night, $calendar, $closingArrival)->all(), 'a room whose arrival is not pushed: a PMS change there is a person');
        self::assertSame('none', $pushed->of('STD', $night, $calendar, null)->get(ChangeField::PMS_RESTRICTION), 'the listing masks when no date rule');
        self::assertFalse($pushed->of('STD', $night, null, null)->has(ChangeField::PMS_MIN_STAY), 'no calendar read: nothing known');
        self::assertSame(Restriction::NoCheckinCheckout, PushedPmsValues::restriction($night, null, new DateOverride($night, checkinMask: '1111011', checkoutMask: '1111011')));
        self::assertNull(PushedPmsValues::restriction($night, new PricingStayNight($night, 1, 0, 1, '1111011', null, false), null), 'masks off on the listing');
        $none = new PushedPmsValues(new PricingSync(['*'], [], ['*']), StaySyncCapability::none());
        self::assertTrue($none->of('STD', $night, $calendar, null)->isEmpty(), 'a connector that carries nothing pushes nothing');
        self::assertCount(1, $pushed->calendar('STD', [self::NIGHT => $calendar], null));
    }

    public function testPairingByItemByCalendarAndByANewItem(): void
    {
        $night = Days::date(self::NIGHT);
        $pairing = new StayPairing(new PushedPmsValues(new PricingSync(['*'], [], ['*']), new StaySyncCapability(StaySyncField::MinStay, StaySyncField::ArrivalDeparture)));
        $stay = self::entry('STD', [ChangeField::PMS_MIN_STAY => 2]);
        $held = self::entry('GDN', [ChangeField::PMS_MIN_STAY => 3]);
        $closed = self::entry('SUP', [ChangeField::PMS_RESTRICTION => 'no_checkin']);
        $items = new MergeResult([new PlanItem('STD', $night, 400.0, minStay: 2)], []);
        $calendar = ['GDN' => [self::NIGHT => new PricingStayNight($night, 3, 0, 2)], 'SUP' => [self::NIGHT => new PricingStayNight($night, 1, 0, 2, '1111111', '1111110')]];
        $result = $pairing->pair([$stay, $held, $closed], $items, $calendar, [], $night);
        self::assertSame('STD|' . self::NIGHT, $result->entries[0]->pairedItem);
        self::assertTrue($result->entries[1]->pricingHolds);
        self::assertSame('SUP|' . self::NIGHT, $result->entries[2]->pairedItem);
        $new = (new MergeResult($result->items->items, []))->item('SUP|' . self::NIGHT);
        self::assertSame(['1111011', '1111110', StayItemFactory::PAIR_REASON], [$new->checkInMask, $new->checkOutMask, $new->reasonCode]);
        self::assertSame([], $result->unpaired);

        $late = $pairing->pair([$closed], new MergeResult([], []), [], [], Days::add($night, -1));
        self::assertSame([$closed], $late->unpaired);
    }

    public function testARevertCountsOnlyWhenThePushPutTheValueBack(): void
    {
        $night = Days::date(self::NIGHT);
        $written = new NightFields([ChangeField::PMS_MIN_STAY => 1]);
        $ledger = (new RevertLedger())
            ->observe('STD', $night, $written, new NightFields([ChangeField::PMS_MIN_STAY => 2]), new NightFields([ChangeField::PMS_MIN_STAY => 2]))
            ->observe('STD', $night, $written, new NightFields([ChangeField::PMS_MIN_STAY => 1]), new NightFields([ChangeField::PMS_MIN_STAY => 2]))
            ->observe('STD', $night, $written, new NightFields([ChangeField::PMS_MIN_STAY => 3]), new NightFields([ChangeField::PMS_MIN_STAY => 2]));
        self::assertSame(1, $ledger->count('STD', $night, ChangeField::PMS_MIN_STAY));
        self::assertSame(['STD|' . self::NIGHT . '|' . ChangeField::PMS_MIN_STAY => 1], $ledger->toArray());
    }

    public function testTheConfigurationIsOffByDefaultAndChecked(): void
    {
        $neutral = StayWriteConfig::fromArray([]);
        self::assertEquals(new StayWriteConfig(), $neutral);
        self::assertFalse($neutral->enabled);
        self::assertSame(366, StayWriteConfig::fromArray(['enabled' => true, 'horizon_days' => 366, 'max_changes_per_run' => 2000])->horizonDays);
        $this->expectException(InvalidConfig::class);
        StayWriteConfig::fromArray(['horizon_days' => 400, 'revert_limit' => 0]);
    }

    /**
     * @param array<string, int|string> $target
     */
    private static function entry(string $room, array $target): PmsChangeEntry
    {
        return new PmsChangeEntry($room, Days::date(self::NIGHT), new NightFields($target), NightFields::none(), 'arrival_day', true);
    }
}
