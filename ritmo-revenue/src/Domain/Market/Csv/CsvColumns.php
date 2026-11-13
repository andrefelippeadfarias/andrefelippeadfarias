<?php

declare(strict_types=1);

namespace Ritmo\Domain\Market\Csv;

/**
 * The columns of a market price file found in its header (names matched case-insensitively and
 * trimmed). Unknown columns are ignored with a warning; a column whose name looks like guest data
 * is ignored with its own warning (the plugin never stores guest data); a missing required column
 * is a file error.
 */
final class CsvColumns
{
    public const REQUIRED = ['competitor', 'check_in', 'check_out', 'price'];

    public const OPTIONAL = ['price_basis', 'room', 'currency', 'captured_at', 'source', 'available'];

    public const GUEST_LIKE = ['name', 'guest', 'guest_name', 'email', 'e-mail', 'mail', 'phone', 'telefone', 'document', 'cpf', 'passport', 'address'];

    public const MAX_COLUMNS = 50;

    /**
     * @param array<string, int>     $positions column => index in a row
     * @param list<MarketCsvProblem> $problems  file errors and warnings of the header
     */
    private function __construct(public readonly array $positions, public readonly array $problems)
    {
    }

    /**
     * @param list<string|null> $header the cells of the first row
     */
    public static function fromHeader(array $header): self
    {
        if (count($header) > self::MAX_COLUMNS) {
            return new self([], [MarketCsvProblem::file(MarketCsvProblem::TOO_MANY_COLUMNS)]);
        }
        $positions = [];
        $problems = [];
        foreach ($header as $index => $cell) {
            $name = strtolower(trim((string) $cell));
            if ($name === '') {
                continue;
            }
            $label = mb_check_encoding($name, 'UTF-8') ? $name : '?';
            if (in_array($name, [...self::REQUIRED, ...self::OPTIONAL], true)) {
                if (isset($positions[$name])) {
                    $problems[] = new MarketCsvProblem(MarketCsvProblem::WARNING, MarketCsvProblem::DUPLICATE_COLUMN, 1, $label);
                    continue;
                }
                $positions[$name] = $index;
            } elseif (in_array($name, self::GUEST_LIKE, true)) {
                $problems[] = new MarketCsvProblem(MarketCsvProblem::WARNING, MarketCsvProblem::GUEST_COLUMN, 1, $label);
            } else {
                $problems[] = new MarketCsvProblem(MarketCsvProblem::WARNING, MarketCsvProblem::UNKNOWN_COLUMN, 1, $label);
            }
        }
        foreach (self::REQUIRED as $required) {
            if (!isset($positions[$required])) {
                $problems[] = MarketCsvProblem::file(MarketCsvProblem::MISSING_COLUMN, $required);
            }
        }

        return new self($positions, $problems);
    }

    public function hasFileError(): bool
    {
        foreach ($this->problems as $problem) {
            if ($problem->kind === MarketCsvProblem::FILE) {
                return true;
            }
        }

        return false;
    }

    /**
     * The trimmed cell of a column in a row; '' when the column is absent or the cell is empty.
     *
     * @param list<string|null> $row
     */
    public function cell(array $row, string $column): string
    {
        $index = $this->positions[$column] ?? null;

        return $index === null ? '' : trim((string) ($row[$index] ?? ''));
    }
}
