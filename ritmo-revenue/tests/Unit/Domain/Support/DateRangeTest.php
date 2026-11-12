<?php

declare(strict_types=1);

namespace Ritmo\Tests\Unit\Domain\Support;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Ritmo\Domain\Support\DateRange;
use Ritmo\Domain\Support\Days;

final class DateRangeTest extends TestCase
{
    public function testARangeIncludesItsFirstAndLastNights(): void
    {
        $range = new DateRange(Days::date('2026-11-27'), Days::date('2026-12-02'));

        self::assertCount(6, $range);
        self::assertSame(['2026-11-27', '2026-11-28', '2026-11-29', '2026-11-30', '2026-12-01', '2026-12-02'], $range->keys());
        self::assertSame('2026-11-27', Days::key($range->first()));
        self::assertSame('2026-12-02', Days::key($range->last()));
    }

    public function testIterationYieldsDateOnlyValuesKeyedByDate(): void
    {
        $seen = [];
        foreach (DateRange::fromStrings('2026-12-31', '2027-01-01') as $key => $night) {
            self::assertInstanceOf(DateTimeImmutable::class, $night);
            $seen[$key] = $night->format(DATE_ATOM);
        }

        self::assertSame(['2026-12-31' => '2026-12-31T00:00:00+00:00', '2027-01-01' => '2027-01-01T00:00:00+00:00'], $seen);
    }

    public function testASingleNight(): void
    {
        $range = DateRange::nights(Days::date('2026-11-11'), 1);

        self::assertCount(1, $range);
        self::assertSame(['2026-11-11'], $range->keys());
    }

    public function testNightsBuildsACountedRange(): void
    {
        $range = DateRange::nights(Days::date('2026-11-11'), 60);

        self::assertCount(60, $range);
        self::assertSame('2027-01-09', Days::key($range->last()));
    }

    public function testStayCoversArrivalToTheDayBeforeDeparture(): void
    {
        $range = DateRange::stay(Days::date('2026-12-30'), Days::date('2027-01-02'));

        self::assertSame(['2026-12-30', '2026-12-31', '2027-01-01'], $range->keys());
    }

    public function testTimesOfDayAndTimezonesAreDropped(): void
    {
        $zone = new DateTimeZone('America/Sao_Paulo');
        $range = new DateRange(new DateTimeImmutable('2026-11-11 22:00', $zone), new DateTimeImmutable('2026-11-12 03:00', $zone));

        self::assertSame(['2026-11-11', '2026-11-12'], $range->keys());
    }

    public function testContains(): void
    {
        $range = DateRange::fromStrings('2026-11-19', '2026-11-21');

        self::assertFalse($range->contains(Days::date('2026-11-18')));
        self::assertTrue($range->contains(Days::date('2026-11-19')));
        self::assertTrue($range->contains(new DateTimeImmutable('2026-11-21T23:59:59Z')));
        self::assertFalse($range->contains(Days::date('2026-11-22')));
    }

    public function testIntersect(): void
    {
        $november = DateRange::fromStrings('2026-11-01', '2026-11-30');

        self::assertSame(['2026-11-29', '2026-11-30'], $november->intersect(DateRange::fromStrings('2026-11-29', '2026-12-05'))?->keys());
        self::assertSame(['2026-11-30'], $november->intersect(DateRange::fromStrings('2026-11-30', '2026-11-30'))?->keys());
        self::assertNull($november->intersect(DateRange::fromStrings('2026-12-01', '2026-12-05')));
    }

    public function testInvalidRangesAreRejected(): void
    {
        $calls = [
            static fn () => new DateRange(Days::date('2026-11-12'), Days::date('2026-11-11')),
            static fn () => DateRange::nights(Days::date('2026-11-11'), 0),
            static fn () => DateRange::stay(Days::date('2026-11-11'), Days::date('2026-11-11')),
            static fn () => DateRange::stay(Days::date('2026-11-12'), Days::date('2026-11-11')),
            static fn () => DateRange::fromStrings('2026-11-31', '2026-12-01'),
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
