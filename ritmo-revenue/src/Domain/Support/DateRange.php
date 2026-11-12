<?php

declare(strict_types=1);

namespace Ritmo\Domain\Support;

use Countable;
use DateTimeImmutable;
use DateTimeInterface;
use Generator;
use InvalidArgumentException;
use IteratorAggregate;

/**
 * An inclusive range of nights: from the first night to the last night, both included.
 *
 * A night is identified by the date the guest sleeps in (the check-in date of that night).
 * A stay arriving on the 10th and departing on the 12th covers the nights 10 and 11.
 *
 * @implements IteratorAggregate<string, DateTimeImmutable>
 */
final class DateRange implements IteratorAggregate, Countable
{
    private DateTimeImmutable $first;

    private DateTimeImmutable $last;

    /**
     * @throws InvalidArgumentException when the last night is before the first
     */
    public function __construct(DateTimeInterface $first, DateTimeInterface $last)
    {
        $this->first = Days::normalize($first);
        $this->last = Days::normalize($last);
        if ($this->last < $this->first) {
            throw new InvalidArgumentException(sprintf(
                'Invalid range: last night %s is before first night %s.',
                Days::key($this->last),
                Days::key($this->first)
            ));
        }
    }

    /**
     * A range of $count nights starting at $first.
     *
     * @throws InvalidArgumentException when $count is lower than 1
     */
    public static function nights(DateTimeInterface $first, int $count): self
    {
        if ($count < 1) {
            throw new InvalidArgumentException('A range needs at least one night.');
        }

        return new self($first, Days::add($first, $count - 1));
    }

    /**
     * The nights of a stay: from the arrival date to the day before the departure date.
     *
     * @throws InvalidArgumentException when the departure is not after the arrival
     */
    public static function stay(DateTimeInterface $arrival, DateTimeInterface $departure): self
    {
        if (Days::diff($arrival, $departure) < 1) {
            throw new InvalidArgumentException('A stay must depart after it arrives.');
        }

        return new self($arrival, Days::add($departure, -1));
    }

    /**
     * Builds a range from two "YYYY-MM-DD" strings (both inclusive).
     */
    public static function fromStrings(string $first, string $last): self
    {
        return new self(Days::date($first), Days::date($last));
    }

    public function first(): DateTimeImmutable
    {
        return $this->first;
    }

    public function last(): DateTimeImmutable
    {
        return $this->last;
    }

    /**
     * Number of nights in the range.
     */
    public function count(): int
    {
        return Days::diff($this->first, $this->last) + 1;
    }

    public function contains(DateTimeInterface $night): bool
    {
        $night = Days::normalize($night);

        return $night >= $this->first && $night <= $this->last;
    }

    /**
     * The nights both ranges share, or null when they do not overlap.
     */
    public function intersect(self $other): ?self
    {
        $first = max($this->first, $other->first);
        $last = min($this->last, $other->last);

        return $last < $first ? null : new self($first, $last);
    }

    /**
     * Iterates the nights in order, keyed by "YYYY-MM-DD".
     *
     * @return Generator<string, DateTimeImmutable>
     */
    public function getIterator(): Generator
    {
        for ($night = $this->first; $night <= $this->last; $night = Days::add($night, 1)) {
            yield Days::key($night) => $night;
        }
    }

    /**
     * The "YYYY-MM-DD" keys of every night, in order.
     *
     * @return list<string>
     */
    public function keys(): array
    {
        return array_keys(iterator_to_array($this));
    }
}
