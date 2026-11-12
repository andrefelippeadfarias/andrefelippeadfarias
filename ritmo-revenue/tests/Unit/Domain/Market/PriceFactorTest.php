<?php

declare(strict_types=1);

namespace Ritmo\Tests\Unit\Domain\Market;

use PHPUnit\Framework\TestCase;
use Ritmo\Domain\Market\PriceFactor;
use Ritmo\Domain\Model\NightType;
use Ritmo\Domain\Model\PricePlan;
use Ritmo\Tests\Unit\Domain\EngineSample;

final class PriceFactorTest extends TestCase
{
    public function testFactorComesFromTheRuleSet(): void
    {
        $factors = new PriceFactor(EngineSample::rules());
        $deluxe = EngineSample::deluxe();
        $standard = EngineSample::standard();

        self::assertSame(1.0, $factors->factor($deluxe, PricePlan::Sent, NightType::Weekend));
        self::assertSame(0.8, $factors->factor($deluxe, PricePlan::PublicRate, NightType::Weekday));
        self::assertSame(0.6, $factors->factor($deluxe, PricePlan::Discounted, NightType::Weekend));
        self::assertSame(0.5, $factors->factor($deluxe, PricePlan::Discounted, NightType::Holiday));
        self::assertSame(0.4, $factors->factor($standard, PricePlan::Discounted, NightType::Holiday), 'the exception of the room wins');
        self::assertSame(0.5, $factors->factor($standard, PricePlan::Discounted, NightType::Weekday));
    }

    public function testThePropertyTableWinsOverTheGeneralOne(): void
    {
        $rules = EngineSample::rules(static function (array $data): array {
            $data['price_factors']['properties']['inn'] = [
                'public' => ['weekday' => 0.7, 'weekend' => 0.7, 'holiday' => 0.7],
                'discounted' => ['weekday' => 0.45, 'weekend' => 0.45, 'holiday' => 0.45],
            ];

            return $data;
        });

        self::assertSame(0.7, (new PriceFactor($rules))->factor(EngineSample::deluxe(), PricePlan::PublicRate, NightType::Weekday));
    }

    public function testConvertsThroughTheSentPlan(): void
    {
        $factors = new PriceFactor(EngineSample::rules());
        $room = EngineSample::deluxe();

        self::assertSame(1000.0, $factors->toSent(800.0, PricePlan::PublicRate, $room, NightType::Weekday));
        self::assertSame(800.0, $factors->fromSent(1000.0, PricePlan::PublicRate, $room, NightType::Weekday));
        self::assertSame(700.0 / 0.6, $factors->toSent(700.0, PricePlan::Discounted, $room, NightType::Weekend));
        self::assertSame(500.0, $factors->convert(800.0, PricePlan::PublicRate, PricePlan::Discounted, $room, NightType::Weekday), '800 public = 1000 sent = 500 discounted');
        self::assertSame(1234.5, $factors->convert(1234.5, PricePlan::Discounted, PricePlan::Discounted, $room, NightType::Weekday));
        self::assertSame(1234.5, $factors->toSent(1234.5, PricePlan::Sent, $room, NightType::Holiday));
        self::assertSame(1234.5, $factors->fromSent(1234.5, PricePlan::Sent, $room, NightType::Holiday));
    }
}
