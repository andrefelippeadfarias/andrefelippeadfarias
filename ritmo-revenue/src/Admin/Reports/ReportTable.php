<?php

declare(strict_types=1);

namespace Ritmo\Admin\Reports;

/**
 * One table of a report as the screen shows it and the CSV exports it: a caption, columns
 * (label and kind), rows of raw values (the first cell names the row) and an optional totals row.
 */
final class ReportTable
{
    /**
     * @param string                                        $id      the CSV's name ("weekly-made")
     * @param list<array{0: string, 1: Cell}>               $columns label, kind
     * @param list<list<string|int|float|null>>             $rows
     * @param list<string|int|float|null>|null              $totals
     */
    public function __construct(
        public readonly string $id,
        public readonly string $caption,
        public readonly array $columns,
        public readonly array $rows,
        public readonly ?array $totals = null,
    ) {
    }

    /**
     * @return list<string>
     */
    public function csvHeader(): array
    {
        $header = array_map(static fn (array $c): string => $c[0], $this->columns);
        if ($this->hasMoney()) {
            $header[] = __('Currency', 'ritmo-revenue');
        }

        return $header;
    }

    /**
     * @return list<list<string|int|float|null>>
     */
    public function csvRows(string $currency): array
    {
        $out = [];
        foreach ($this->rows as $row) {
            $line = [];
            foreach ($this->columns as $i => [, $cell]) {
                $line[] = $cell->csv($row[$i] ?? null);
            }
            if ($this->hasMoney()) {
                $line[] = $currency;
            }
            $out[] = $line;
        }

        return $out;
    }

    /**
     * @param list<string|int|float|null> $row
     *
     * @return list<string> each cell as a person reads it
     */
    public function formatted(array $row, string $currency): array
    {
        $out = [];
        foreach ($this->columns as $i => [, $cell]) {
            $out[] = $cell->format($row[$i] ?? null, $currency);
        }

        return $out;
    }

    public function isEmpty(): bool
    {
        return $this->rows === [];
    }

    private function hasMoney(): bool
    {
        foreach ($this->columns as [, $cell]) {
            if ($cell->isMoney()) {
                return true;
            }
        }

        return false;
    }
}
