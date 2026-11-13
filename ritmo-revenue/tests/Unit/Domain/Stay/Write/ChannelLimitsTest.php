<?php

declare(strict_types=1);

namespace Ritmo\Tests\Unit\Domain\Stay\Write;

use PHPUnit\Framework\TestCase;
use Ritmo\Domain\Stay\Write\ChannelLimits;

final class ChannelLimitsTest extends TestCase
{
    public function testLimitsAreReadAsNumbersOrNumericText(): void
    {
        $read = ChannelLimits::read([
            'booking' => ['maxBookings' => '0'],
            'airbnb' => ['maxBookings' => 2],
            'expedia' => ['maxBookings' => null],
            'vrbo' => [],
            'agoda' => ['maxBookings' => '1.5'],
            'Bad Name' => ['maxBookings' => 1],
        ]);
        self::assertSame(['airbnb' => 2, 'booking' => 0], $read->limits);
        self::assertSame(['agoda'], $read->ignored);
        self::assertSame([], ChannelLimits::read(null)->limits);
    }
}
