<?php

declare(strict_types=1);

namespace Ritmo\Tests\Unit\Domain\Pricing\Cadence;

use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Ritmo\Domain\Model\PriceNight;
use Ritmo\Domain\Plan\PlanItem;
use Ritmo\Domain\Pricing\Cadence\CadenceConfig;
use Ritmo\Domain\Pricing\Cadence\CadenceVerdict;
use Ritmo\Domain\Pricing\Cadence\NoEffectFilter;
use Ritmo\Domain\Pricing\Cadence\SoldBase;
use Ritmo\Domain\Pricing\Cadence\StepCadence;
use Ritmo\Domain\Pricing\Cadence\StepLedger;
use Ritmo\Domain\Pricing\Cadence\StepProposal;
use Ritmo\Domain\Pricing\Cadence\StepRecord;
use Ritmo\Domain\Pricing\StepDirection;
use Ritmo\Tests\Support\ParityNames;
use Ritmo\Tests\Unit\Domain\Pricing\Ladder\Reference;

final class CadenceBehaviourTest extends TestCase
{
    private const NIGHT = '2027-03-16';

    public function testEachExceptionFollowsItsSwitch(): void
    {
        $night = new DateTimeImmutable(self::NIGHT);
        $now = new DateTimeImmutable('2027-03-08T16:53:00Z');
        $down = new StepLedger([$this->record(StepDirection::Down, '2027-03-08T10:58:00Z', 4)]);

        self::assertTrue((new StepCadence())->permits($down, ParityNames::room('dual'), $night, StepDirection::Up, $now)->allowed);
        $strict = new StepCadence(new CadenceConfig(oppositeDirectionImmediate: false));
        self::assertSame(CadenceVerdict::NOT_ON_CHANNEL, $strict->permits($down, ParityNames::room('dual'), $night, StepDirection::Up, $now)->code);

        self::assertTrue((new StepCadence())->permits($down, ParityNames::room('dual'), $night, StepDirection::Down, $now, 3, SoldBase::Room, 7)->allowed);
        $noRelease = new StepCadence(new CadenceConfig(releaseOnBooking: false));
        self::assertFalse($noRelease->permits($down, ParityNames::room('dual'), $night, StepDirection::Down, $now, 3, SoldBase::Room, 7)->allowed);

        $arrived = new StepLedger([$this->record(StepDirection::Down, '2027-03-08T10:58:00Z', 4, '2027-03-08T11:30:00Z')]);
        self::assertSame(CadenceVerdict::WITHIN_INTERVAL, (new StepCadence())->permits($arrived, ParityNames::room('dual'), $night, StepDirection::Down, $now)->code);
        self::assertTrue((new StepCadence(new CadenceConfig(minHoursBetweenSteps: 5)))->permits($arrived, ParityNames::room('dual'), $night, StepDirection::Down, $now)->allowed);
        self::assertTrue((new StepCadence(new CadenceConfig(requireChannelArrival: false, minHoursBetweenSteps: 0)))->permits($down, ParityNames::room('dual'), $night, StepDirection::Down, $now)->allowed);
    }

    public function testTheNewOrderExceptionCanBeSwitchedOff(): void
    {
        $order = Reference::orders(['o' => ['id' => 'o', 'tipo' => 'alvo_por_data', 'quartos' => [ParityNames::room('low')], 'de' => self::NIGHT, 'ate' => self::NIGHT, 'data' => '2027-03-08', 'criada_em' => '2027-03-08T15:00:00Z']])->find('o');
        $today = new DateTimeImmutable('2027-03-08');

        self::assertTrue((new StepCadence())->newOrderBypass(new StepLedger(), ParityNames::room('low'), new DateTimeImmutable(self::NIGHT), $order, $today));
        self::assertFalse((new StepCadence(new CadenceConfig(newOrderImmediateOnce: false)))->newOrderBypass(new StepLedger(), ParityNames::room('low'), new DateTimeImmutable(self::NIGHT), $order, $today));
    }

    public function testOnlyStepsWithADirectionAreRecorded(): void
    {
        $night = new DateTimeImmutable(self::NIGHT);
        $ledger = (new StepLedger())->record([
            new StepProposal(ParityNames::room('low'), $night, StepRecord::LADDER, StepDirection::Up, 1000.0, 1099.0, 1098, 2, SoldBase::Room, 7),
            new StepProposal(ParityNames::room('low'), $night, StepRecord::LADDER, null, 1000.0, 1010.0, 1010, 2, SoldBase::Room, 7),
        ], new DateTimeImmutable('2027-03-08T16:58:20.5-03:00'));

        self::assertCount(1, $ledger->records());
        self::assertSame('2027-03-08T19:58:20+00:00', $ledger->records()[0]->toArray()['written_at']);
        self::assertTrue($ledger->hasLadderStep(ParityNames::room('low'), $night));
        self::assertSame(1, $ledger->pendingCount());
    }

    public function testALedgerRowRoundTripsAndKeepsItsMinutePrecision(): void
    {
        $row = ['room' => ParityNames::room('dual'), 'night' => self::NIGHT, 'price_from' => 1300.0, 'price_to' => 1105.0, 'direction' => 'down', 'source_status' => 'very_behind',
            'sold' => 0, 'sold_base' => 'property', 'sold_total' => 9, 'written_at' => '2027-03-08T10:58+00:00', 'on_channel_at' => '2027-03-08T11:30+00:00'];
        $record = StepRecord::fromArray($row);

        self::assertTrue($record->minutePrecision);
        self::assertSame($row, $record->toArray());
        self::assertEquals(new DateTimeImmutable('2027-03-08T10:58:59Z'), $record->writtenAtLatest());
        $this->expectException(InvalidArgumentException::class);
        StepRecord::fromArray(['written_at' => 'yesterday'] + $row);
    }

    public function testRoundingOnlyItemsAreDroppedWhenSwitchedOn(): void
    {
        $night = new DateTimeImmutable('2026-11-24');
        $live = [PlanItem::keyOf(ParityNames::room('low'), $night) => new PriceNight($night, 1232.0, 1220.0, 5000.0, null, 1, 7)];
        $samePercent = new PlanItem(ParityNames::room('low'), $night, 1225.0);
        $higher = new PlanItem(ParityNames::room('low'), $night, 1240.0);
        $withStay = new PlanItem(ParityNames::room('low'), $night, 1225.0, null, 2);
        $result = (new NoEffectFilter())->filter([$samePercent, $higher, $withStay], Reference::rooms(), $live, []);

        self::assertSame([$samePercent], $result->roundingOnly);
        self::assertSame([$higher, $withStay], $result->kept);
        self::assertSame([$samePercent, $higher, $withStay], (new NoEffectFilter(false))->filter([$samePercent, $higher, $withStay], Reference::rooms(), $live, [])->kept);
        $this->expectException(InvalidArgumentException::class);
        (new NoEffectFilter())->filter([new PlanItem('Nope', $night, 1.0)], Reference::rooms(), [], []);
    }

    private function record(StepDirection $direction, string $written, int $sold, ?string $onChannel = null): StepRecord
    {
        return new StepRecord(ParityNames::room('dual'), new DateTimeImmutable(self::NIGHT), 1300.0, 1200.0, $direction, 'behind', $sold, SoldBase::Room, 7, new DateTimeImmutable($written), false, $onChannel === null ? null : new DateTimeImmutable($onChannel));
    }
}
