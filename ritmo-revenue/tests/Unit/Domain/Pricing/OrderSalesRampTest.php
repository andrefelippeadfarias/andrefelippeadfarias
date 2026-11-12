<?php

declare(strict_types=1);

namespace Ritmo\Tests\Unit\Domain\Pricing;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Ritmo\Domain\Model\PaceStatus;
use Ritmo\Domain\Pricing\HardFloor;
use Ritmo\Domain\Pricing\OrderSalesRamp;
use Ritmo\Domain\Pricing\PaceReading;

/**
 * The sales ramp of an owner target order (reference: the escada_ordem branch of pace._plano).
 */
final class OrderSalesRampTest extends TestCase
{
    /**
     * A ramp from the order's floor of 500 to a top of 900, a fallback floor and three descent steps.
     *
     * @param array<string, mixed> $with
     */
    private static function ramp(array $with = []): OrderSalesRamp
    {
        return new OrderSalesRamp(
            'order-1',
            $with['share'] ?? 0.0,
            $with['top'] ?? 900.0,
            $with['standard'] ?? 400.0,
            $with['below'] ?? false,
            array_key_exists('fallback', $with) ? $with['fallback'] : null,
            $with['descent'] ?? [],
            $with['marks'] ?? [7, 5, 3, 1],
            0.02,
            $with['weak'] ?? true,
            $with['raise'] ?? null,
        );
    }

    private static function floor(float $amount): HardFloor
    {
        return new HardFloor($amount, 'order_floor', [['label' => 'order_floor', 'amount' => $amount]]);
    }

    private static function behind(): PaceReading
    {
        return new PaceReading(PaceStatus::Behind, 10.0, 40.0);
    }

    private static function onCurve(): PaceReading
    {
        return new PaceReading(PaceStatus::OnPace, 50.0, 40.0);
    }

    public function testTheLineClimbsFromTheFloorToTheTopWithTheShareSold(): void
    {
        self::assertSame(500.0, self::ramp()->line(500.0, 300.0));
        self::assertSame(700.0, self::ramp(['share' => 0.5])->line(500.0, 300.0));
        self::assertSame(900.0, self::ramp(['share' => 1.0])->line(500.0, 300.0));
        self::assertSame(800.0, self::ramp()->line(500.0, 800.0), 'never under the market target');
        self::assertSame(1000.0, self::ramp(['share' => 0.5])->line(1000.0, 300.0), 'a floor above the top is its own top');
    }

    public function testUnderTheLineThePriceClimbsByAtMostOneStepLimit(): void
    {
        $step = self::ramp(['share' => 0.5])->step(self::floor(500.0), 300.0, 600.0, 20, self::onCurve(), 10.0);

        self::assertSame(700.0, $step->line);
        self::assertEqualsWithDelta(660.0, $step->price, 1e-9);
        self::assertSame(500.0, $step->floor->amount);
    }

    public function testAboveTheLineAWeakNightGoesStraightToItAndAStrongNightStays(): void
    {
        self::assertSame(500.0, self::ramp()->step(self::floor(500.0), 300.0, 800.0, 20, self::onCurve(), 10.0)->price);
        self::assertSame(800.0, self::ramp(['weak' => false])->step(self::floor(500.0), 300.0, 800.0, 20, self::onCurve(), 10.0)->price);
    }

    public function testTheFallbackFloorLowersTheFloorOfTheRamp(): void
    {
        $step = self::ramp(['fallback' => 450.0])->step(self::floor(500.0), 300.0, 800.0, 5, self::onCurve(), 10.0);

        self::assertSame(450.0, $step->floor->amount);
        self::assertSame(OrderSalesRamp::FALLBACK_FLOOR, $step->floor->label);
        self::assertSame(450.0, $step->price);
        self::assertSame(500.0, self::ramp(['fallback' => 550.0])->step(self::floor(500.0), 300.0, 800.0, 5, self::onCurve(), 10.0)->floor->amount, 'a fallback above the floor does nothing');
    }

    public function testTheDescentGoesOneStepPerMarkReachedAndOneAtATime(): void
    {
        $ramp = self::ramp(['descent' => [480.0, 460.0, 440.0]]);

        self::assertNull($ramp->descentFloor(8, self::behind()), 'before the first mark');
        self::assertNull($ramp->descentFloor(5, self::onCurve()), 'on its curve');
        self::assertSame(480.0, $ramp->descentFloor(7, self::behind()));
        self::assertSame(440.0, $ramp->descentFloor(1, self::behind()), 'the last step at most');
        self::assertSame(480.0, $ramp->descentFloor(1, self::behind(), 800.0, 300.0), 'from a price above every step, one step at a time');
        self::assertSame(460.0, $ramp->descentFloor(1, self::behind(), 480.0, 300.0), 'the first step reached: the next one');
        self::assertSame(475.0, self::ramp(['descent' => [480.0, 460.0], 'standard' => 475.0])->descentFloor(3, self::behind()), 'never under the standard floor ...');
        self::assertSame(460.0, self::ramp(['descent' => [480.0, 460.0], 'standard' => 475.0, 'below' => true])->descentFloor(3, self::behind()), '... unless the order releases it');
    }

    public function testADescendedNightGoesStraightToTheLineEvenWhenItSellsWell(): void
    {
        $step = self::ramp(['descent' => [480.0], 'weak' => false])->step(self::floor(500.0), 300.0, 800.0, 6, self::behind(), 10.0);

        self::assertSame(OrderSalesRamp::DESCENT_STEP, $step->floor->label);
        self::assertSame(480.0, $step->price);
    }

    public function testBackOnItsCurveANightClimbsBackByAtMostOneStepLimit(): void
    {
        $ramp = self::ramp(['descent' => [480.0, 460.0]]);

        self::assertEqualsWithDelta(440.0, $ramp->returnLimit(3, 400.0, 500.0, 10.0), 1e-9);
        self::assertNull($ramp->returnLimit(9, 400.0, 500.0, 10.0), 'outside the window of the steps');
        self::assertNull($ramp->returnLimit(3, 500.0, 500.0, 10.0), 'the price is at the floor');
        self::assertNull($ramp->returnLimit(3, 460.0, 500.0, 10.0), 'one step reaches the floor');
        self::assertNull(self::ramp()->returnLimit(3, 400.0, 500.0, 10.0), 'an order without descent steps');

        $step = $ramp->step(self::floor(500.0), 300.0, 400.0, 3, self::onCurve(), 10.0);
        self::assertSame(OrderSalesRamp::RETURN_LIMIT, $step->floor->label);
        self::assertEqualsWithDelta(440.0, $step->floor->amount, 1e-9);
        self::assertEqualsWithDelta(440.0, $step->price, 1e-9);
    }

    public function testARaiseIsAppliedOverTheFloorOfTheRamp(): void
    {
        $raise = new HardFloor(470.0, 'relation_premium', []);
        $step = self::ramp(['fallback' => 450.0])->withRaise($raise)->step(self::floor(500.0), 300.0, 800.0, 5, self::onCurve(), 10.0);

        self::assertSame(470.0, $step->floor->amount);
        self::assertSame('relation_premium', $step->floor->label);
        self::assertSame(450.0, $step->line, 'the line of the ramp is drawn before the raise');
    }

    public function testTheShareSoldIsBetweenZeroAndOne(): void
    {
        $this->expectException(InvalidArgumentException::class);
        self::ramp(['share' => 1.2]);
    }
}
