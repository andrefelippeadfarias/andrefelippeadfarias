<?php

declare(strict_types=1);

namespace Ritmo\Domain\Market\Csv;

/**
 * One problem of a market price file: a file error (nothing is imported), a row error (the row is
 * skipped) or a warning (the row is imported). The screen turns the code into a sentence; the
 * value is the cell as typed, cut to 40 characters, only for that sentence and never stored.
 */
final class MarketCsvProblem
{
    public const FILE = 'file';

    public const ERROR = 'error';

    public const WARNING = 'warning';

    // File errors: nothing is imported.
    public const MISSING_COLUMN = 'missing_column';
    public const NOT_UTF8 = 'not_utf8';
    public const TOO_MANY_ROWS = 'too_many_rows';
    public const TOO_MANY_COLUMNS = 'too_many_columns';
    public const FILE_TOO_LARGE = 'file_too_large';
    public const NOT_CSV = 'not_csv';
    public const EMPTY_FILE = 'empty_file';
    public const ALREADY_IMPORTED = 'already_imported';

    // Row errors: the row is skipped.
    public const UNKNOWN_COMPETITOR = 'unknown_competitor';
    public const BAD_DATE = 'bad_date';
    public const CHECKOUT_NOT_AFTER_CHECKIN = 'checkout_not_after_checkin';
    public const STAY_TOO_LONG = 'stay_too_long';
    public const STAY_IN_PAST = 'stay_in_past';
    public const BAD_PRICE = 'bad_price';
    public const BAD_BASIS = 'bad_basis';
    public const BAD_ROOM = 'bad_room';
    public const BAD_CURRENCY = 'bad_currency';
    public const CURRENCY_MISMATCH = 'currency_mismatch';
    public const BAD_CAPTURE = 'bad_capture';
    public const CAPTURE_IN_FUTURE = 'capture_in_future';
    public const BAD_AVAILABLE = 'bad_available';
    public const BAD_SOURCE = 'bad_source';
    public const CELL_TOO_LONG = 'cell_too_long';
    public const ROW_NOT_UTF8 = 'row_not_utf8';

    // Warnings: the row is imported.
    public const CAPTURE_TOO_OLD = 'capture_too_old';
    public const DUPLICATE_IN_FILE = 'duplicate_in_file';
    public const CAPTURE_MISSING = 'capture_missing';
    public const UNKNOWN_COLUMN = 'unknown_column';
    public const GUEST_COLUMN = 'guest_column';
    public const DUPLICATE_COLUMN = 'duplicate_column';

    private const VALUE_MAX = 40;

    public readonly string $value;

    /**
     * @param int    $line   the line of the file (1 is the header); 0 for the whole file
     * @param string $column the column name as the header names it, or ''
     */
    public function __construct(
        public readonly string $kind,
        public readonly string $code,
        public readonly int $line = 0,
        public readonly string $column = '',
        string $value = '',
    ) {
        $this->value = mb_substr(mb_check_encoding($value, 'UTF-8') ? $value : '?', 0, self::VALUE_MAX);
    }

    public static function file(string $code, string $column = ''): self
    {
        return new self(self::FILE, $code, 0, $column);
    }

    /**
     * The problem without the cell's value: what may be stored with an import.
     *
     * @return array{line: int, column: string, code: string}
     */
    public function toStored(): array
    {
        return ['line' => $this->line, 'column' => $this->column, 'code' => $this->code];
    }
}
