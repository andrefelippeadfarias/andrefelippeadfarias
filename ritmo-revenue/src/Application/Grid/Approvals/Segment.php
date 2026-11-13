<?php

declare(strict_types=1);

namespace Ritmo\Application\Grid\Approvals;

use DateTimeImmutable;
use Ritmo\Domain\Support\Days;

/**
 * A run of consecutive nights of one room with the same reason, the same direction and the same
 * "special" key: one line of the approval screen that opens to its nights.
 */
final class Segment
{
    /**
     * @param non-empty-list<EntryRow> $entries in night order
     */
    public function __construct(public readonly string $room, public readonly array $entries)
    {
    }

    public function first(): DateTimeImmutable
    {
        return $this->entries[0]->night ?? Days::date('1970-01-01');
    }

    public function last(): DateTimeImmutable
    {
        return $this->entries[count($this->entries) - 1]->night ?? $this->first();
    }

    /** Entries in the segment (a deletion and its rewrite are two entries of one night). */
    public function count(): int
    {
        return count($this->entries);
    }

    public function nights(): int
    {
        return count(array_unique(array_map(static fn (EntryRow $e): string => $e->night === null ? $e->subject : Days::key($e->night), $this->entries)));
    }

    public function reason(): string
    {
        return $this->entries[0]->reason;
    }

    public function direction(): string
    {
        return $this->entries[0]->direction;
    }

    /**
     * @return array{0: float, 1: float}|null lowest and highest amount before
     */
    public function beforeRange(): ?array
    {
        return self::range(array_map(static fn (EntryRow $e): ?float => $e->before(), $this->entries));
    }

    /**
     * @return array{0: float, 1: float}|null
     */
    public function afterRange(): ?array
    {
        return self::range(array_map(static fn (EntryRow $e): ?float => $e->after(), $this->entries));
    }

    public function medianChange(): ?float
    {
        return self::median(array_values(array_filter(array_map(static fn (EntryRow $e): ?float => $e->changePercent(), $this->entries), 'is_float')));
    }

    /**
     * @return list<string> the warnings of any of its nights
     */
    public function warnings(): array
    {
        $all = [];
        foreach ($this->entries as $entry) {
            $all = [...$all, ...$entry->warnings];
        }

        return array_values(array_intersect(EntryWarnings::ALL, array_unique($all)));
    }

    /**
     * @return list<string>
     */
    public function keys(): array
    {
        return array_map(static fn (EntryRow $e): string => $e->key, $this->entries);
    }

    /**
     * @param list<float> $values
     */
    public static function median(array $values): ?float
    {
        if ($values === []) {
            return null;
        }
        sort($values);
        $middle = intdiv(count($values), 2);

        return count($values) % 2 === 1 ? $values[$middle] : ($values[$middle - 1] + $values[$middle]) / 2;
    }

    /**
     * @param list<float|null> $values
     *
     * @return array{0: float, 1: float}|null
     */
    private static function range(array $values): ?array
    {
        $known = array_values(array_filter($values, 'is_float'));

        return $known === [] ? null : [min($known), max($known)];
    }
}
