<?php

declare(strict_types=1);

namespace Ritmo\Tests\Unit\Domain\Support;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Ritmo\Domain\Support\Num;

final class NumTest extends TestCase
{
    /**
     * @return array<string, array{float, int, float}>
     */
    public static function pythonRounding(): array
    {
        return [
            'half goes to even: 0.5 -> 0' => [0.5, 0, 0.0],
            'half goes to even: 1.5 -> 2' => [1.5, 0, 2.0],
            'half goes to even: 2.5 -> 2' => [2.5, 0, 2.0],
            'half goes to even: 3.5 -> 4' => [3.5, 0, 4.0],
            'negative half: -0.5 -> 0' => [-0.5, 0, 0.0],
            'negative half: -1.5 -> -2' => [-1.5, 0, -2.0],
            'negative half: -2.5 -> -2' => [-2.5, 0, -2.0],
            'just below half' => [0.49999999999999994, 0, 0.0],
            'amount tie 1762.5 -> 1762' => [1762.5, 0, 1762.0],
            'amount tie 1461.5 -> 1462' => [1461.5, 0, 1462.0],
            '2.675 is stored below the tie' => [2.675, 2, 2.67],
            '1.005 is stored below the tie' => [1.005, 2, 1.0],
            '1.115 is stored below the tie' => [1.115, 2, 1.11],
            'exact tie 0.125 -> 0.12' => [0.125, 2, 0.12],
            'exact tie 0.375 -> 0.38' => [0.375, 2, 0.38],
            'exact tie 0.25 -> 0.2' => [0.25, 1, 0.2],
            'exact tie 0.75 -> 0.8' => [0.75, 1, 0.8],
            '0.35 is stored below the tie' => [0.35, 1, 0.3],
            'no rounding needed' => [723.9, 2, 723.9],
            'three decimals' => [814.8275, 3, 814.827],
        ];
    }

    #[DataProvider('pythonRounding')]
    public function testRoundHalfEvenReproducesPythonRound(float $value, int $decimals, float $expected): void
    {
        self::assertSame($expected, Num::roundHalfEven($value, $decimals));
    }

    public function testRoundHalfEvenIsNotPhpRound(): void
    {
        // The cases that make a dedicated function necessary: PHP rounds half away from zero.
        self::assertSame(3.0, round(2.5));
        self::assertSame(2.0, Num::roundHalfEven(2.5));
        self::assertSame(2.68, round(2.675, 2));
        self::assertSame(2.67, Num::roundHalfEven(2.675, 2));
    }

    public function testRoundingToZeroNeverReturnsNegativeZero(): void
    {
        self::assertSame('0', (string) Num::roundHalfEven(-0.4));
        self::assertSame('0', (string) Num::roundHalfEven(-0.004, 2));
    }

    public function testRoundIntReturnsAnInteger(): void
    {
        self::assertSame(2, Num::roundInt(2.5));
        self::assertSame(1324, Num::roundInt(1323.5));
        self::assertSame(-2, Num::roundInt(-1.5));
    }

    public function testRoundingRejectsInvalidInput(): void
    {
        foreach ([[NAN, 0], [INF, 0], [-INF, 2], [1.5, -1], [1.5, 16]] as [$value, $decimals]) {
            try {
                Num::roundHalfEven($value, $decimals);
                self::fail('Expected an InvalidArgumentException.');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testRoundIntRejectsNumbersTooLargeForAnInteger(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Num::roundInt(1e30);
    }

    public function testCeilEpsForgivesNoiseAboveAWholeNumber(): void
    {
        self::assertSame(14, Num::ceilEps(14.000000000000002));
        self::assertSame(14, Num::ceilEps(14.0));
        self::assertSame(14, Num::ceilEps(14.0000000005));
        self::assertSame(15, Num::ceilEps(14.00000001));
        self::assertSame(15, Num::ceilEps(14.1));
        self::assertSame(-30, Num::ceilEps(-30.999999));
        self::assertSame(-31, Num::ceilEps(-30.9999999999));
        self::assertSame(-31, Num::ceilEps(-31.0));
    }

    public function testFloorEpsForgivesNoiseBelowAWholeNumber(): void
    {
        self::assertSame(14, Num::floorEps(13.999999999999998));
        self::assertSame(14, Num::floorEps(14.0));
        self::assertSame(14, Num::floorEps(13.9999999995));
        self::assertSame(13, Num::floorEps(13.99999999));
        self::assertSame(13, Num::floorEps(13.9));
        self::assertSame(-31, Num::floorEps(-30.000001));
        self::assertSame(-30, Num::floorEps(-30.0000000001));
    }

    public function testCeilIntAndFloorIntHaveNoTolerance(): void
    {
        self::assertSame(15, Num::ceilInt(14.000000000000002));
        self::assertSame(15, Num::ceilInt(14.0000000005));
        self::assertSame(14, Num::ceilInt(14.0));
        self::assertSame(-30, Num::ceilInt(-30.9));
        self::assertSame(13, Num::floorInt(13.999999999999998));
        self::assertSame(13, Num::floorInt(13.9999999995));
        self::assertSame(14, Num::floorInt(14.0));
        self::assertSame(-31, Num::floorInt(-30.1));
    }

    public function testCeilAndFloorRejectNonFiniteNumbers(): void
    {
        foreach (['ceilEps', 'floorEps', 'ceilInt', 'floorInt'] as $method) {
            try {
                Num::$method(NAN);
                self::fail('Expected an InvalidArgumentException.');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testClamp(): void
    {
        self::assertSame(5.0, Num::clamp(5.0, 1.0, 10.0));
        self::assertSame(1.0, Num::clamp(-3.0, 1.0, 10.0));
        self::assertSame(10.0, Num::clamp(11.5, 1.0, 10.0));
        self::assertSame(2.0, Num::clamp(7.0, 2.0, 2.0));
    }

    public function testClampRejectsAnInvertedRange(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Num::clamp(1.0, 10.0, 2.0);
    }

    public function testClampRejectsNonFiniteNumbers(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Num::clamp(NAN, 0.0, 1.0);
    }

    public function testFloorPercentRoundsUpAndCeilingPercentRoundsDown(): void
    {
        // 700 over a base of 1000 is exactly -30%: both agree.
        self::assertSame(-30, Num::floorToPercent(700.0, 1000.0));
        self::assertSame(-30, Num::ceilingToPercent(700.0, 1000.0));
        // 705 is -29.5%: the floor goes up to -29 (710), the ceiling down to -30 (700).
        self::assertSame(-29, Num::floorToPercent(705.0, 1000.0));
        self::assertSame(-30, Num::ceilingToPercent(705.0, 1000.0));
        // Above the base.
        self::assertSame(13, Num::floorToPercent(1125.0, 1000.0));
        self::assertSame(12, Num::ceilingToPercent(1125.0, 1000.0));
    }

    public function testPercentToAmount(): void
    {
        self::assertSame(690.0, Num::percentToAmount(-31.0, 1000.0));
        self::assertSame(1610.0, Num::percentToAmount(61.0, 1000.0));
        // A tie goes to the even amount, as in the reference engine.
        self::assertSame(1762.0, Num::percentToAmount(50.0, 1175.0));
        self::assertSame(810.75, Num::percentToAmount(-31.0, 1175.0, 2));
        self::assertSame(1027.82, Num::percentToAmount(-31.0, 1489.6, 2));
    }

    public function testEffectiveFloorIsNeverBelowAndEffectiveCeilingNeverAboveTheAmountAsked(): void
    {
        foreach ([1175.0, 1260.0, 1490.0, 1610.0, 2000.0, 333.0] as $base) {
            for ($amount = 0.31 * $base; $amount < 2.2 * $base; $amount += 7.77) {
                $floor = Num::percentToAmount(Num::floorToPercent($amount, $base), $base, 2);
                $ceiling = Num::percentToAmount(Num::ceilingToPercent($amount, $base), $base, 2);
                self::assertGreaterThanOrEqual($amount - 0.005, $floor, "floor of $amount over $base");
                self::assertLessThanOrEqual($amount + 0.005, $ceiling, "ceiling of $amount over $base");
                self::assertLessThanOrEqual(0.01 * $base + 0.005, $floor - $ceiling, 'floor and ceiling are at most one point apart');
            }
        }
    }

    public function testPercentConversionsRejectABaseThatIsNotPositive(): void
    {
        $calls = [
            static fn () => Num::floorToPercent(100.0, 0.0),
            static fn () => Num::ceilingToPercent(100.0, -5.0),
            static fn () => Num::percentToAmount(10.0, 0.0),
            static fn () => Num::floorToPercent(NAN, 100.0),
            static fn () => Num::percentToAmount(INF, 100.0),
        ];
        foreach ($calls as $call) {
            try {
                $call();
                self::fail('Expected an InvalidArgumentException.');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
