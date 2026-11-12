<?php

declare(strict_types=1);

namespace Ritmo\Tests\Unit\Domain\Market;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Ritmo\Domain\Market\Quantile;

final class QuantileTest extends TestCase
{
    public function testInterpolatesBetweenTheClosestRanks(): void
    {
        $values = [300, 100, 200, 400];

        self::assertSame(100.0, Quantile::of($values, 0.0));
        self::assertSame(400.0, Quantile::of($values, 1.0));
        self::assertSame(250.0, Quantile::of($values, 0.5));
        self::assertSame(175.0, Quantile::of($values, 0.25));
        self::assertEqualsWithDelta(370.0, Quantile::of($values, 0.9), 1e-9);
    }

    public function testASingleValueIsEveryQuantile(): void
    {
        self::assertSame(420.0, Quantile::of([420], 0.0));
        self::assertSame(420.0, Quantile::of([420.0], 0.75));
    }

    public function testAnEmptyListHasNoQuantile(): void
    {
        self::assertNan(Quantile::of([], 0.5));
    }

    public function testRejectsAFractionOutsideZeroToOne(): void
    {
        foreach ([-0.01, 1.01, NAN] as $fraction) {
            try {
                Quantile::of([1, 2, 3], $fraction);
                self::fail('Expected an InvalidArgumentException.');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testRejectsValuesThatAreNotFiniteNumbers(): void
    {
        foreach ([[1, 'two', 3], [1.0, INF], [null]] as $values) {
            try {
                Quantile::of($values, 0.5);
                self::fail('Expected an InvalidArgumentException.');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testMedian(): void
    {
        self::assertSame(200.0, Quantile::median([300, 100, 200]));
        self::assertSame(250.0, Quantile::median([400, 100, 300, 200]));
        self::assertSame(7.5, Quantile::median([7, 8]));
        self::assertSame(5.0, Quantile::median([5]));
    }

    public function testTheMedianOfNothingIsAnError(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Quantile::median([]);
    }
}
