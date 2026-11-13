<?php

declare(strict_types=1);

namespace Ritmo\Tests\Unit\Application\Write\Calendar;

use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Ritmo\Application\Write\Calendar\PmsCalendarChanges;
use Ritmo\Application\Write\Calendar\StayWriteRound;
use Ritmo\Application\Write\Calendar\UndoPmsEntries;
use Ritmo\Domain\Calendar\Stay\PricingSync;
use Ritmo\Domain\Calendar\Stay\StaySyncCapability;
use Ritmo\Domain\Calendar\Stay\StaySyncField;
use Ritmo\Domain\Model\RoomType;
use Ritmo\Domain\Plan\PlanItem;
use Ritmo\Domain\Stay\Write\PmsChangeEntry;
use Ritmo\Domain\Stay\Write\PmsCheckContext;
use Ritmo\Domain\Stay\Write\RevertLedger;
use Ritmo\Domain\Stay\Write\StayWriteConfig;
use Ritmo\Domain\Stay\Write\StayWritePlan;
use Ritmo\Domain\Support\Days;
use Ritmo\Domain\Write\Change\PmsRestore;
use Ritmo\Domain\Write\State\ChangeField;
use Ritmo\Domain\Write\State\NightFields;

final class PmsCalendarChangesTest extends TestCase
{
    private const NIGHT = '2027-03-15';

    public function testEachAcceptedChangeBecomesOneCalendarNightWithItsGuard(): void
    {
        $stay = self::entry([ChangeField::PMS_MIN_STAY => 3], [ChangeField::PMS_MIN_STAY => 2], true)->with(false, 'STD|' . self::NIGHT);
        $held = self::entry([ChangeField::PMS_RESTRICTION => 'no_checkin'], [ChangeField::PMS_RESTRICTION => 'none'], true)->with(true, null);
        $limits = self::entry([ChangeField::channelLimit('booking') => 0, ChangeField::PMS_AVAILABLE => 1], []);
        [$a, $b, $c] = PmsCalendarChanges::of([$stay, $held, $limits], self::rooms());

        self::assertSame(['roomId' => '902001', 'from' => self::NIGHT, 'to' => self::NIGHT, 'minStay' => 3], $a->range());
        self::assertSame(2, $a->minStayBefore);
        self::assertTrue($a->alsoPricing, 'paired: the pricing step goes first');
        self::assertSame(['roomId' => '902001', 'from' => self::NIGHT, 'to' => self::NIGHT, 'override' => 'noCheckIn'], $b->range());
        self::assertFalse($b->alsoPricing, 'held by the pricing calendar: goes alone');
        self::assertSame(['override' => 'none'], $b->readBefore(), 'the restriction read is guarded right before the write');
        self::assertSame(['minStay' => 2], $a->readBefore());
        self::assertSame([], $c->readBefore(), 'availability and channel limits are not stay values');
        self::assertSame(['roomId' => '902001', 'from' => self::NIGHT, 'to' => self::NIGHT, 'numAvail' => 1, 'channels' => ['booking' => 0]], $c->range());
        foreach ([$a, $b, $c] as $change) {
            self::assertStringNotContainsString('price', json_encode($change->range(), JSON_THROW_ON_ERROR));
        }
    }

    public function testAnUnknownRoomIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        PmsCalendarChanges::of([new PmsChangeEntry('ZZ', Days::date(self::NIGHT), new NightFields([ChangeField::PMS_MIN_STAY => 2]), NightFields::none(), 'arrival_day')], self::rooms());
    }

    public function testAnUndoOfAPushedFieldNeedsThePricingUndoOfTheSameNight(): void
    {
        $undo = new UndoPmsEntries(new PricingSync(['STD'], [], []), new StaySyncCapability(StaySyncField::MinStay));
        $restore = new PmsRestore('STD', Days::date(self::NIGHT), new NightFields([ChangeField::PMS_MIN_STAY => 2]), [17]);
        $read = ['STD' => [self::NIGHT => new NightFields([ChangeField::PMS_MIN_STAY => 1, ChangeField::PMS_AVAILABLE => 3])]];
        [$alone] = $undo->of([$restore], $read, []);
        self::assertTrue($alone->needsPricingStep());
        self::assertNull($alone->pairedItem);
        self::assertSame([ChangeField::PMS_AVAILABLE => 3, ChangeField::PMS_MIN_STAY => 1], $alone->before->all());
        [$paired] = $undo->of([$restore], $read, [new PlanItem('STD', Days::date(self::NIGHT), minStay: 2, reasonCode: 'UNDO')]);
        self::assertSame('STD|' . self::NIGHT, $paired->pairedItem);
        self::assertSame([17], $paired->undoOf);

        $round = new StayWriteRound();
        $context = self::context(['STD|' . self::NIGHT => true]);
        self::assertCount(1, $round->batch(new StayWritePlan([$paired], [], [], [], []), $context, self::rooms())->changes);
        $refused = $round->batch(new StayWritePlan([$alone], [], [], [], []), $context, self::rooms());
        self::assertSame([], $refused->changes);
        self::assertSame('pms_unpaired', $refused->check->refused[0]['code']);
    }

    public function testTheRoundRaisesTheCapAlert(): void
    {
        $entries = [self::entry([ChangeField::PMS_MIN_STAY => 3], []), self::entry([ChangeField::PMS_MIN_STAY => 4], [])];
        $targets = [];
        foreach ($entries as $e) {
            $targets[$e->identity()] = true;
        }
        $batch = (new StayWriteRound())->batch(new StayWritePlan($entries, [], ['a', 'b'], $targets, []), self::context([], 1), self::rooms());
        self::assertSame(['pms_changes_capped'], $batch->alerts());
        self::assertSame(2, $batch->mergeConflicts);
    }

    /**
     * @param array<string, int|string> $target
     * @param array<string, int|string> $before
     */
    private static function entry(array $target, array $before, bool $alsoPricing = false): PmsChangeEntry
    {
        return new PmsChangeEntry('STD', Days::date(self::NIGHT), new NightFields($target), new NightFields($before), 'arrival_day', $alsoPricing, null, false, null, [], null);
    }

    /**
     * @return array<string, RoomType>
     */
    private static function rooms(): array
    {
        return ['STD' => new RoomType('inn', 'STD', 'Standard Room', 4, '902001', 'L1', 480.0, 320.0, 1600.0, 320.0)];
    }

    /**
     * @param array<string, true> $accepted
     */
    private static function context(array $accepted, int $max = 300): PmsCheckContext
    {
        return new PmsCheckContext(Days::date('2027-03-03'), new DateTimeImmutable('2027-03-03T12:00:00Z'), self::rooms(), [], [], [], $accepted, [], new RevertLedger(), 14, 24, new StayWriteConfig(true, 60, $max, 2, false));
    }
}
