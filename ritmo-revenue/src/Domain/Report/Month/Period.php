<?php

declare(strict_types=1);

namespace Ritmo\Domain\Report\Month;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Ritmo\Domain\Support\Days;

/**
 * A calendar month at the property: its first night, the night after its last, the instant its
 * first day starts at the property and its number of days.
 */
final class Period
{
    private function __construct(
        public readonly string $key,
        public readonly DateTimeImmutable $first,
        public readonly DateTimeImmutable $end,
        public readonly DateTimeImmutable $startsAt,
        public readonly int $days,
    ) {
    }

    /**
     * @param string $month "YYYY-MM"
     *
     * @throws InvalidArgumentException on a malformed month
     */
    public static function of(string $month, string $timezone): self
    {
        if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month) !== 1) {
            throw new InvalidArgumentException(sprintf('Invalid month "%s": expected YYYY-MM.', $month));
        }
        $first = Days::date($month . '-01');
        $end = $first->modify('first day of next month');
        $startsAt = new DateTimeImmutable($month . '-01 00:00:00', new DateTimeZone($timezone));

        return new self($month, $first, $end, $startsAt, Days::diff($first, $end));
    }

    /**
     * The month a night belongs to, as "YYYY-MM".
     */
    public static function keyOf(DateTimeImmutable $night): string
    {
        return $night->format('Y-m');
    }

    /**
     * The month of $day and the next $count - 1, as "YYYY-MM".
     *
     * Reference: revisao_12m.meses.
     *
     * @return list<string>
     */
    public static function following(DateTimeImmutable $day, int $count): array
    {
        $month = Days::date($day->format('Y-m') . '-01');
        $out = [];
        for ($i = 0; $i < $count; ++$i) {
            $out[] = $month->format('Y-m');
            $month = $month->modify('first day of next month');
        }

        return $out;
    }

    public function contains(DateTimeImmutable $night): bool
    {
        return $night >= $this->first && $night < $this->end;
    }

    /**
     * The month's number (1-12).
     */
    public function number(): int
    {
        return (int) $this->first->format('n');
    }
}
