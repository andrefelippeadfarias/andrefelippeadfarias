<?php

declare(strict_types=1);

namespace Ritmo\Tests\Unit\Domain\Support;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Ritmo\Domain\Support\Clock;
use Ritmo\Domain\Support\FixedClock;
use Ritmo\Domain\Support\SystemClock;

final class ClockTest extends TestCase
{
    public function testFixedClockReturnsItsInstantInUtc(): void
    {
        $clock = new FixedClock('2026-11-11T19:53:00-03:00');

        self::assertInstanceOf(Clock::class, $clock);
        self::assertSame('2026-11-11T22:53:00+00:00', $clock->now()->format(DATE_ATOM));
        self::assertSame('UTC', $clock->now()->getTimezone()->getName());
    }

    public function testFixedClockReadsAStringWithoutOffsetAsUtc(): void
    {
        self::assertSame('2026-11-11T07:53:00+00:00', (new FixedClock('2026-11-11 07:53'))->now()->format(DATE_ATOM));
        self::assertSame('2026-11-11T00:00:00+00:00', (new FixedClock('2026-11-11'))->now()->format(DATE_ATOM));
    }

    public function testFixedClockAcceptsADateTimeInAnyTimezone(): void
    {
        $local = new DateTimeImmutable('2026-07-01 09:00', new DateTimeZone('Europe/Lisbon'));

        self::assertSame('2026-07-01T08:00:00+00:00', (new FixedClock($local))->now()->format(DATE_ATOM));
    }

    public function testTodayIsTheLocalCalendarDateAsADateOnlyValue(): void
    {
        // 01:30 UTC on the 12th is still the evening of the 11th in Sao Paulo and already the 12th in Tokyo.
        $clock = new FixedClock('2026-11-12T01:30:00Z');

        self::assertSame('2026-11-11T00:00:00+00:00', $clock->today('America/Sao_Paulo')->format(DATE_ATOM));
        self::assertSame('2026-11-12T00:00:00+00:00', $clock->today('Asia/Tokyo')->format(DATE_ATOM));
        self::assertSame('2026-11-12T00:00:00+00:00', $clock->today('UTC')->format(DATE_ATOM));
    }

    public function testTodayRejectsAnUnknownTimezone(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new FixedClock('2026-11-12T01:30:00Z'))->today('Mars/Olympus');
    }

    /**
     * @return array<string, array{string}>
     */
    public static function relativeOrBrokenInstants(): array
    {
        return [
            'now' => ['now'],
            'empty' => [''],
            'relative' => ['tomorrow'],
            'relative offset' => ['+1 day'],
            'words' => ['first monday of january'],
            'impossible time' => ['2026-11-11T25:61'],
        ];
    }

    #[DataProvider('relativeOrBrokenInstants')]
    public function testFixedClockRejectsAnythingThatIsNotAnAbsoluteInstant(string $value): void
    {
        $this->expectException(InvalidArgumentException::class);
        new FixedClock($value);
    }

    public function testPlusSecondsReturnsANewClock(): void
    {
        $clock = new FixedClock('2026-11-11T23:59:30Z');
        $later = $clock->plusSeconds(45);

        self::assertSame('2026-11-11T23:59:30+00:00', $clock->now()->format(DATE_ATOM));
        self::assertSame('2026-11-12T00:00:15+00:00', $later->now()->format(DATE_ATOM));
        self::assertSame('2026-11-11T23:58:30+00:00', $clock->plusSeconds(-60)->now()->format(DATE_ATOM));
    }

    public function testSystemClockReadsTheSystemTimeInUtc(): void
    {
        $clock = new SystemClock();
        $before = time();
        $now = $clock->now();
        $after = time();

        self::assertSame('UTC', $now->getTimezone()->getName());
        self::assertGreaterThanOrEqual($before, $now->getTimestamp());
        self::assertLessThanOrEqual($after, $now->getTimestamp());
        self::assertSame('00:00:00', $clock->today('America/Sao_Paulo')->format('H:i:s'));
        self::assertLessThanOrEqual(1, abs((int) $clock->today('UTC')->diff($now)->format('%a')));
    }
}
