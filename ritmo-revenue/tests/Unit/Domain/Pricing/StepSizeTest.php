<?php

declare(strict_types=1);

namespace Ritmo\Tests\Unit\Domain\Pricing;

use PHPUnit\Framework\TestCase;
use Ritmo\Domain\Pricing\StepDirection;
use Ritmo\Domain\Pricing\StepSize;
use Ritmo\Tests\Unit\Domain\EngineSample;

/**
 * Sample rules: k 0.5; largest step 5% from 30 days out, 10% from 8, 15% closer; tolerance 2%.
 */
final class StepSizeTest extends TestCase
{
    public function testTheLargestStepDependsOnTheDaysLeft(): void
    {
        $steps = new StepSize(EngineSample::rules());

        self::assertSame(5.0, $steps->maxPercent(45));
        self::assertSame(5.0, $steps->maxPercent(30));
        self::assertSame(10.0, $steps->maxPercent(29));
        self::assertSame(10.0, $steps->maxPercent(8));
        self::assertSame(15.0, $steps->maxPercent(7));
        self::assertSame(15.0, $steps->maxPercent(0));
        self::assertSame(7.5, $steps->maxPercent(0, true), 'half with a weak anchor');
        self::assertSame(2.5, $steps->maxPercent(45, true));
    }

    public function testAStepCoversPartOfTheDistance(): void
    {
        $steps = new StepSize(EngineSample::rules());

        self::assertSame(1050.0, $steps->next(1000.0, 1100.0, 5), 'half of +10%');
        self::assertSame(950.0, $steps->next(1000.0, 900.0, 5), 'half of -10%');
        self::assertSame(1000.0, $steps->next(1000.0, 1000.0, 5));
    }

    public function testAStepIsLimitedInBothDirections(): void
    {
        $steps = new StepSize(EngineSample::rules());

        self::assertEqualsWithDelta(1150.0, $steps->next(1000.0, 2000.0, 5), 1e-9, '+15% close to the night');
        self::assertEqualsWithDelta(850.0, $steps->next(1000.0, 100.0, 5), 1e-9);
        self::assertEqualsWithDelta(1100.0, $steps->next(1000.0, 2000.0, 20), 1e-9, '+10%');
        self::assertEqualsWithDelta(1050.0, $steps->next(1000.0, 2000.0, 40), 1e-9, '+5%');
        self::assertEqualsWithDelta(1075.0, $steps->next(1000.0, 2000.0, 5, true), 1e-9, 'half the limit with a weak anchor');
        self::assertEqualsWithDelta(925.0, $steps->next(1000.0, 100.0, 5, true), 1e-9);
        self::assertSame(1040.0, $steps->next(1000.0, 1080.0, 5, true), 'under the limit the weak anchor changes nothing');
    }

    public function testAPriceOfZeroCannotStep(): void
    {
        self::assertSame(0.0, (new StepSize(EngineSample::rules()))->next(0.0, 900.0, 5));
    }

    public function testAChangeWithinTheToleranceHasNoDirection(): void
    {
        $steps = new StepSize(EngineSample::rules());

        self::assertNull($steps->direction(1000.0, 1000.0));
        self::assertNull($steps->direction(1000.0, 1020.0), 'exactly +2%');
        self::assertSame(StepDirection::Up, $steps->direction(1000.0, 1020.01));
        self::assertNull($steps->direction(1000.0, 980.0), 'exactly -2%');
        self::assertSame(StepDirection::Down, $steps->direction(1000.0, 979.99));
    }
}
