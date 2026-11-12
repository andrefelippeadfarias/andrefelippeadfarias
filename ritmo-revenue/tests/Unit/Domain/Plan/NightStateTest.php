<?php

declare(strict_types=1);

namespace Ritmo\Tests\Unit\Domain\Plan;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Ritmo\Domain\Model\DateOverride;
use Ritmo\Domain\Model\PriceNight;
use Ritmo\Domain\Plan\NightState;
use Ritmo\Domain\Support\Days;

final class NightStateTest extends TestCase
{
    public function testFromTakesTheCalendarNightAndTheOverrideInForce(): void
    {
        $night = Days::date('2027-03-10');
        $price = new PriceNight($night, 950.0, 800.0, 1200.0, 1, 2, 4);
        $override = new DateOverride($night, -20.0, 20.0, 2);

        $state = NightState::from($price, $override);

        self::assertSame(
            ['price' => 950.0, 'min_price' => 800.0, 'max_price' => 1200.0, 'min_stay' => 2, 'min_percent' => -20.0, 'max_percent' => 20.0],
            $state->toArray()
        );
        self::assertSame(1, NightState::from($price)->minStay, 'without an override, the stay of the calendar');
        self::assertNull(NightState::from($price)->minPercent);
        self::assertSame(-20.0, NightState::from(null, $override)->minPercent);
        self::assertNull(NightState::from(null, $override)->price);
        self::assertSame(array_fill_keys(['price', 'min_price', 'max_price', 'min_stay', 'min_percent', 'max_percent'], null), NightState::from(null)->toArray());
    }

    public function testRejectsInvalidValues(): void
    {
        $builders = [
            static fn (): NightState => new NightState(-1.0),
            static fn (): NightState => new NightState(900.0, NAN),
            static fn (): NightState => new NightState(900.0, 800.0, -5.0),
            static fn (): NightState => new NightState(900.0, 800.0, 1000.0, 0),
            static fn (): NightState => new NightState(900.0, 800.0, 1000.0, 2, INF),
        ];
        foreach ($builders as $build) {
            try {
                $build();
                self::fail('Expected an InvalidArgumentException.');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
