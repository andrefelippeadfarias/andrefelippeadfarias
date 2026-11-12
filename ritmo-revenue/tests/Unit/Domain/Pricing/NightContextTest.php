<?php

declare(strict_types=1);

namespace Ritmo\Tests\Unit\Domain\Pricing;

use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Ritmo\Domain\Model\PriceNight;
use Ritmo\Domain\Model\RoomType;
use Ritmo\Domain\Pricing\LedgerStep;
use Ritmo\Domain\Pricing\NightContext;
use Ritmo\Domain\Pricing\StepDirection;
use Ritmo\Domain\Support\Days;
use Ritmo\Tests\Unit\Domain\EngineSample;

final class NightContextTest extends TestCase
{
    public function testThePaceIsMeasuredOverTheRoomUnlessAGroupIsGiven(): void
    {
        $room = EngineSample::deluxe();
        $own = new NightContext(EngineSample::property(), $room, Days::date('2027-03-10'), 900.0, 1, 3, null);

        self::assertSame(1, $own->paceSold);
        self::assertSame(4, $own->paceUnits, 'the units of the room type, even with 3 on sale that night');
        self::assertFalse($own->usesGroupPace());

        $group = new NightContext(EngineSample::property(), $room, Days::date('2027-03-10'), 900.0, 1, 4, null, null, null, null, 7, 10);
        self::assertSame([7, 10], [$group->paceSold, $group->paceUnits]);
        self::assertTrue($group->usesGroupPace());
    }

    public function testSoldOutWhenEveryUnitOnSaleIsSold(): void
    {
        $context = static fn (int $sold, int $units): NightContext => new NightContext(EngineSample::property(), EngineSample::deluxe(), Days::date('2027-03-10'), 900.0, $sold, $units, null);

        self::assertFalse($context(3, 4)->isSoldOut());
        self::assertTrue($context(4, 4)->isSoldOut());
        self::assertTrue($context(5, 4)->isSoldOut(), 'overbooked');
        self::assertTrue($context(3, 3)->isSoldOut(), 'only 3 units on sale that night');
        self::assertFalse($context(0, 0)->isSoldOut(), 'nothing on sale is not sold out');
    }

    public function testFromPriceNightTakesWhatThePricingSystemReports(): void
    {
        $step = new LedgerStep(StepDirection::Up, new DateTimeImmutable('2027-03-02T10:00:00-03:00'), true);
        $price = new PriceNight(new DateTimeImmutable('2027-03-10T15:00:00+00:00'), 950.0, 800.0, 1200.0, 2, 3, 4);
        $anchor = EngineSample::anchor('2027-03-10');

        $context = NightContext::fromPriceNight(EngineSample::property(), EngineSample::deluxe(), $price, $anchor, $step);

        self::assertSame('2027-03-10 00:00:00', $context->night->format('Y-m-d H:i:s'));
        self::assertSame([950.0, 800.0, 1200.0], [$context->currentPrice, $context->floorInForce, $context->ceilingInForce]);
        self::assertSame([3, 4], [$context->sold, $context->units]);
        self::assertSame($anchor, $context->anchor);
        self::assertSame($step, $context->lastStep);
        self::assertSame('2027-03-02T13:00:00+00:00', $step->recordedAt->format('c'), 'the ledger instant is kept in UTC');
    }

    public function testRejectsInconsistentInput(): void
    {
        $property = EngineSample::property();
        $room = EngineSample::deluxe();
        $night = Days::date('2027-03-10');
        $foreign = new RoomType('other', 'Suite', 'Suite', 1, '', '', 900.0, 300.0, 1500.0, 400.0);
        $builders = [
            'room of another property' => static fn (): NightContext => new NightContext($property, $foreign, $night, 900.0, 0, 1, null),
            'negative price' => static fn (): NightContext => new NightContext($property, $room, $night, -1.0, 0, 4, null),
            'price that is not a number' => static fn (): NightContext => new NightContext($property, $room, $night, NAN, 0, 4, null),
            'negative floor in force' => static fn (): NightContext => new NightContext($property, $room, $night, 900.0, 0, 4, null, -5.0),
            'negative sold' => static fn (): NightContext => new NightContext($property, $room, $night, 900.0, -1, 4, null),
            'negative units' => static fn (): NightContext => new NightContext($property, $room, $night, 900.0, 0, -4, null),
            'half a pace basis' => static fn (): NightContext => new NightContext($property, $room, $night, 900.0, 0, 4, null, null, null, null, 3),
            'negative pace basis' => static fn (): NightContext => new NightContext($property, $room, $night, 900.0, 0, 4, null, null, null, null, -1, 10),
        ];
        foreach ($builders as $name => $build) {
            try {
                $build();
                self::fail("Expected an InvalidArgumentException for: $name.");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
