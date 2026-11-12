<?php

declare(strict_types=1);

namespace Ritmo\Tests\Unit\Domain\Market;

use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Ritmo\Domain\Market\PriceSample;
use Ritmo\Domain\Support\Days;

final class PriceSampleTest extends TestCase
{
    public function testKeepsDatesAsDateOnlyValues(): void
    {
        $sample = new PriceSample(new DateTimeImmutable('2027-03-10T18:30:00+00:00'), 640.5, new DateTimeImmutable('2027-03-01T09:00:00+00:00'), 'inn-next-door');

        self::assertSame('2027-03-10', Days::key($sample->night));
        self::assertSame('00:00:00', $sample->night->format('H:i:s'));
        self::assertSame('2027-03-01', Days::key($sample->capturedAt));
        self::assertSame(640.5, $sample->publicPrice);
        self::assertSame('inn-next-door', $sample->source);
        self::assertSame('', (new PriceSample(Days::date('2027-03-10'), 1.0, Days::date('2027-03-01')))->source);
    }

    public function testRejectsAPriceThatIsNotPositive(): void
    {
        foreach ([0.0, -10.0, NAN, INF] as $price) {
            try {
                new PriceSample(Days::date('2027-03-10'), $price, Days::date('2027-03-01'));
                self::fail('Expected an InvalidArgumentException.');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
