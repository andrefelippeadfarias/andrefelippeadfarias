<?php

declare(strict_types=1);

namespace Ritmo\Tests\Unit\Domain\Support;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Ritmo\Domain\Support\Days;

final class DaysTest extends TestCase
{
    public function testDateParsesAStrictDateAsMidnightUtc(): void
    {
        $date = Days::date('2026-11-11');

        self::assertSame('2026-11-11T00:00:00+00:00', $date->format(DATE_ATOM));
        self::assertSame('2026-11-11', Days::key($date));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidDates(): array
    {
        return [
            'day that does not exist' => ['2026-02-30'],
            'month 13' => ['2026-13-01'],
            'no leading zeros' => ['2026-2-3'],
            'other order' => ['11/11/2026'],
            'with time' => ['2026-11-11T10:00:00'],
            'relative' => ['today'],
            'empty' => [''],
        ];
    }

    #[DataProvider('invalidDates')]
    public function testDateRejectsWhatIsNotARealDate(string $value): void
    {
        self::assertFalse(Days::isValid($value));
        $this->expectException(InvalidArgumentException::class);
        Days::date($value);
    }

    public function testIsValidAcceptsRealDates(): void
    {
        self::assertTrue(Days::isValid('2028-02-29'));
        self::assertTrue(Days::isValid('2026-12-31'));
    }

    public function testNormalizeKeepsTheCalendarDateOfTheValue(): void
    {
        $late = new DateTimeImmutable('2026-11-11 23:30', new DateTimeZone('America/Sao_Paulo'));

        self::assertSame('2026-11-11T00:00:00+00:00', Days::normalize($late)->format(DATE_ATOM));
    }

    public function testLocalDate(): void
    {
        $instant = new DateTimeImmutable('2026-11-12T01:30:00Z');

        self::assertSame('2026-11-11', Days::key(Days::localDate($instant, 'America/Sao_Paulo')));
        self::assertSame('2026-11-12', Days::key(Days::localDate($instant, 'Europe/Lisbon')));
    }

    public function testLocalDateRejectsAnUnknownTimezone(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Days::localDate(new DateTimeImmutable('2026-11-12T01:30:00Z'), 'Nowhere/None');
    }

    public function testAddMovesWholeDaysAcrossMonthsAndYears(): void
    {
        self::assertSame('2026-12-01', Days::key(Days::add(Days::date('2026-11-30'), 1)));
        self::assertSame('2027-01-01', Days::key(Days::add(Days::date('2026-12-31'), 1)));
        self::assertSame('2026-10-31', Days::key(Days::add(Days::date('2026-11-01'), -1)));
        self::assertSame('2028-02-29', Days::key(Days::add(Days::date('2028-02-28'), 1)));
        self::assertSame('2026-11-11', Days::key(Days::add(Days::date('2026-11-11'), 0)));
    }

    public function testDiffIsSignedAndIgnoresTheTimeOfDay(): void
    {
        $today = Days::date('2026-11-11');

        self::assertSame(0, Days::diff($today, $today));
        self::assertSame(60, Days::diff($today, Days::date('2027-01-10')));
        self::assertSame(-11, Days::diff($today, Days::date('2026-10-31')));
        self::assertSame(1, Days::diff(new DateTimeImmutable('2026-11-11T23:59:00Z'), new DateTimeImmutable('2026-11-12T00:01:00Z')));
    }

    public function testDiffDoesNotDriftAcrossADaylightSavingChange(): void
    {
        // Lisbon leaves summer time on 2026-10-25: local days there are 25 hours long.
        $zone = new DateTimeZone('Europe/Lisbon');
        $from = new DateTimeImmutable('2026-10-24 12:00', $zone);
        $to = new DateTimeImmutable('2026-10-26 12:00', $zone);

        self::assertSame(2, Days::diff($from, $to));
    }

    public function testWeekdayFollowsPythonMondayIsZero(): void
    {
        // 2026-11-09 is a Monday.
        $expected = ['2026-11-09' => 0, '2026-11-10' => 1, '2026-11-11' => 2, '2026-11-12' => 3, '2026-11-13' => 4, '2026-11-14' => 5, '2026-11-15' => 6];
        foreach ($expected as $date => $weekday) {
            self::assertSame($weekday, Days::weekday(Days::date($date)), $date);
        }
    }
}
