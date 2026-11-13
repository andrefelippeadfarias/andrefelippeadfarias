<?php

declare(strict_types=1);

namespace Ritmo\Domain\Market\Csv;

/**
 * What reading a market price file gave: the valid rows (to import), every problem, and whether a
 * file error stops the whole import.
 */
final class CsvReadResult
{
    /**
     * @param list<ParsedRow>        $rows     valid rows, the later of two duplicates kept
     * @param list<MarketCsvProblem> $problems in file order
     * @param int                    $total    data rows read (blank lines not counted)
     */
    public function __construct(public readonly array $rows, public readonly array $problems, public readonly int $total)
    {
    }

    public static function failed(MarketCsvProblem ...$problems): self
    {
        return new self([], array_values($problems), 0);
    }

    public function hasFileError(): bool
    {
        return $this->ofKind(MarketCsvProblem::FILE) !== [];
    }

    /**
     * @return list<MarketCsvProblem>
     */
    public function ofKind(string $kind): array
    {
        return array_values(array_filter($this->problems, static fn (MarketCsvProblem $p): bool => $p->kind === $kind));
    }

    /**
     * Lines skipped because of a row error (each counted once).
     */
    public function invalidLines(): int
    {
        return count(array_unique(array_map(static fn (MarketCsvProblem $p): int => $p->line, $this->ofKind(MarketCsvProblem::ERROR))));
    }
}
