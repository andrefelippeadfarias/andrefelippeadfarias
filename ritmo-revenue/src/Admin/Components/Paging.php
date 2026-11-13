<?php

declare(strict_types=1);

namespace Ritmo\Admin\Components;

/**
 * One page of a list: which rows to read (offset and limit) and the "1–50 of 230" line.
 * Immutable; a page beyond the last is moved back to the last.
 */
final class Paging
{
    public readonly int $page;

    public function __construct(int $page, public readonly int $perPage, public readonly int $total)
    {
        $pages = max(1, (int) ceil(max(0, $total) / max(1, $perPage)));
        $this->page = min(max(1, $page), $pages);
    }

    public function offset(): int
    {
        return ($this->page - 1) * $this->perPage;
    }

    public function pages(): int
    {
        return max(1, (int) ceil(max(0, $this->total) / max(1, $this->perPage)));
    }

    public function hasPrevious(): bool
    {
        return $this->page > 1;
    }

    public function hasNext(): bool
    {
        return $this->page < $this->pages();
    }

    /** "1–50 of 230"; "0 of 0" for an empty list. */
    public function label(): string
    {
        if ($this->total <= 0) {
            return __('none', 'ritmo-revenue');
        }
        $first = $this->offset() + 1;
        $last = min($this->total, $this->offset() + $this->perPage);

        /* translators: 1: first row shown, 2: last row shown, 3: rows in all */
        return sprintf(__('%1$s–%2$s of %3$s', 'ritmo-revenue'), number_format_i18n($first), number_format_i18n($last), number_format_i18n($this->total));
    }
}
