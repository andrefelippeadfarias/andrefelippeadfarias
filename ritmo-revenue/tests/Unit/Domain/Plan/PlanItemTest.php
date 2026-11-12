<?php

declare(strict_types=1);

namespace Ritmo\Tests\Unit\Domain\Plan;

use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Ritmo\Domain\Plan\NightState;
use Ritmo\Domain\Plan\PlanItem;
use Ritmo\Domain\Pricing\NightContext;
use Ritmo\Domain\Pricing\PaceEngine;
use Ritmo\Domain\Pricing\RuleCode;
use Ritmo\Domain\Support\Days;
use Ritmo\Domain\Support\FixedClock;
use Ritmo\Tests\Unit\Domain\EngineSample;

final class PlanItemTest extends TestCase
{
    public function testHoldsWhatItSetsAndWhy(): void
    {
        $before = new NightState(1000.0, 600.0);
        $item = new PlanItem('Deluxe', new DateTimeImmutable('2027-03-10T21:00:00+00:00'), 850.0, 900.0, 2, 'pace_step', ['direction' => 'down'], $before);

        self::assertSame('Deluxe|2027-03-10', $item->key());
        self::assertSame('00:00:00', $item->date->format('H:i:s'));
        self::assertSame(
            [
                'room' => 'Deluxe',
                'date' => '2027-03-10',
                'min_price' => 850.0,
                'max_price' => 900.0,
                'min_stay' => 2,
                'reason_code' => 'pace_step',
                'reason_params' => ['direction' => 'down'],
                'before' => $before->toArray(),
            ],
            $item->toArray()
        );
        self::assertNull((new PlanItem('Deluxe', Days::date('2027-03-10'), null, null, 2))->toArray()['before']);
    }

    public function testRejectsAnItemWithoutShape(): void
    {
        $night = Days::date('2027-03-10');
        $builders = [
            'sets nothing' => static fn (): PlanItem => new PlanItem('Deluxe', $night),
            'invalid room code' => static fn (): PlanItem => new PlanItem('not a code', $night, 900.0),
            'floor of zero' => static fn (): PlanItem => new PlanItem('Deluxe', $night, 0.0),
            'negative ceiling' => static fn (): PlanItem => new PlanItem('Deluxe', $night, null, -10.0),
            'floor that is not a number' => static fn (): PlanItem => new PlanItem('Deluxe', $night, NAN),
            'stay of zero nights' => static fn (): PlanItem => new PlanItem('Deluxe', $night, null, null, 0),
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

    public function testAnItemMayBreakTheRulesTheValidatorChecks(): void
    {
        $item = new PlanItem('Deluxe', Days::date('2020-01-01'), 900.0, 100.0);

        self::assertSame([900.0, 100.0], [$item->minPrice, $item->maxPrice], 'a past night with the ceiling under the floor can be built');
    }

    public function testFromDecisionWritesAStepOfTheEngine(): void
    {
        $engine = new PaceEngine(EngineSample::rules(), new FixedClock('2027-03-03T12:00:00+00:00'));
        $context = static fn (int $sold): NightContext => new NightContext(EngineSample::property(), EngineSample::deluxe(), Days::date('2027-03-10'), 1000.0, $sold, 4, EngineSample::anchor('2027-03-10'));
        $step = $engine->decide($context(0));

        $item = PlanItem::fromDecision($step);
        self::assertSame(['Deluxe', '2027-03-10', 850.0, 850.0, null], [$item->roomCode, Days::key($item->date), $item->minPrice, $item->maxPrice, $item->minStay]);
        self::assertSame(RuleCode::PACE_STEP, $item->reasonCode);
        self::assertSame('down', $item->reasonParams['direction']);
        self::assertSame($step->trace, $item->reasonParams['rule_trace']);
        self::assertSame(1000.0, $item->before?->price, 'without a state, at least the price the engine saw');
        self::assertNull($item->before->minPrice);

        $before = new NightState(1000.0, 600.0, 3000.0, 1, -40.0);
        self::assertSame($before, PlanItem::fromDecision($step, $before)->before);

        $this->expectException(InvalidArgumentException::class);
        PlanItem::fromDecision($engine->decide($context(4)));
    }
}
