<?php

declare(strict_types=1);

namespace Ritmo\Domain\Market\Csv;

use DateTimeImmutable;
use Ritmo\Domain\Market\Series\RoomBasis;
use Ritmo\Domain\Support\Days;
use Ritmo\Domain\Support\Num;

/**
 * Reads the rows of a market price file (already split into cells) into valid rows and problems.
 *
 * The first row is the header (spec 06, 1.2). A row with an error is skipped with its line,
 * column and reason (its warnings are dropped); a row with only warnings is imported; a file error
 * imports nothing. Two rows with the same deduplication key: the later one wins (warning on the
 * earlier line).
 */
final class MarketCsvReader
{
    public const CELL_MAX_BYTES = 1000;

    private const SOURCE_PATTERN = '/^[a-z0-9][a-z0-9_-]{0,31}$/';

    private const FUTURE_SLACK_SECONDS = 3600;

    public function __construct(private readonly CsvContext $context)
    {
    }

    /**
     * @param list<list<string|null>> $rows the file's records, header first; a blank line is [null] or []
     */
    public function read(array $rows): CsvReadResult
    {
        $records = [];
        foreach ($rows as $index => $row) {
            if (!self::isBlank($row)) {
                $records[$index + 1] = $row;
            }
        }
        if ($records === []) {
            return CsvReadResult::failed(MarketCsvProblem::file(MarketCsvProblem::EMPTY_FILE));
        }
        $headerLine = array_key_first($records);
        $columns = CsvColumns::fromHeader(array_values($records[$headerLine]));
        unset($records[$headerLine]);
        if ($columns->hasFileError()) {
            return CsvReadResult::failed(...$columns->problems);
        }
        if (count($records) > $this->context->maxRows) {
            return CsvReadResult::failed(MarketCsvProblem::file(MarketCsvProblem::TOO_MANY_ROWS));
        }

        $problems = $columns->problems;
        $kept = [];
        foreach ($records as $line => $row) {
            $rowProblems = [];
            $parsed = $this->row($columns, array_values($row), $line, $rowProblems);
            $problems = [...$problems, ...$rowProblems];
            if ($parsed === null) {
                continue;
            }
            if (isset($kept[$parsed->dedupKey])) {
                $problems[] = new MarketCsvProblem(MarketCsvProblem::WARNING, MarketCsvProblem::DUPLICATE_IN_FILE, $kept[$parsed->dedupKey]->line);
                unset($kept[$parsed->dedupKey]);
            }
            $kept[$parsed->dedupKey] = $parsed;
        }
        usort($problems, static fn (MarketCsvProblem $a, MarketCsvProblem $b): int => $a->line <=> $b->line);

        return new CsvReadResult(array_values($kept), $problems, count($records));
    }

    /**
     * @param list<string|null>       $row
     * @param list<MarketCsvProblem> $problems filled with the row's problems
     */
    private function row(CsvColumns $columns, array $row, int $line, array &$problems): ?ParsedRow
    {
        foreach ($row as $cell) {
            $cell = (string) $cell;
            if (strlen($cell) > self::CELL_MAX_BYTES) {
                $problems[] = new MarketCsvProblem(MarketCsvProblem::ERROR, MarketCsvProblem::CELL_TOO_LONG, $line);

                return null;
            }
            if (!mb_check_encoding($cell, 'UTF-8')) {
                $problems[] = new MarketCsvProblem(MarketCsvProblem::ERROR, MarketCsvProblem::ROW_NOT_UTF8, $line);

                return null;
            }
        }
        $error = static function (string $code, string $column) use (&$problems, $line, $columns, $row): void {
            $problems[] = new MarketCsvProblem(MarketCsvProblem::ERROR, $code, $line, $column, $columns->cell($row, $column));
        };
        $warning = static function (string $code, string $column) use (&$problems, $line, $columns, $row): void {
            $problems[] = new MarketCsvProblem(MarketCsvProblem::WARNING, $code, $line, $column, $columns->cell($row, $column));
        };
        $before = count($problems);

        $competitorId = $this->context->competitors->find($columns->cell($row, 'competitor'));
        if ($competitorId === null) {
            $error(MarketCsvProblem::UNKNOWN_COMPETITOR, 'competitor');
        }
        [$checkIn, $nights] = $this->stay($columns, $row, $error);
        $price = CsvValues::price($columns->cell($row, 'price'));
        if ($price === null) {
            $error(MarketCsvProblem::BAD_PRICE, 'price');
        }
        $perNight = match (strtolower($columns->cell($row, 'price_basis'))) {
            '', 'total' => false,
            'per_night' => true,
            default => null,
        };
        if ($perNight === null) {
            $error(MarketCsvProblem::BAD_BASIS, 'price_basis');
        }
        $basis = match (strtolower($columns->cell($row, 'room'))) {
            '' => RoomBasis::Cheapest,
            default => RoomBasis::tryFrom(strtolower($columns->cell($row, 'room'))),
        };
        if ($basis === null) {
            $error(MarketCsvProblem::BAD_ROOM, 'room');
        }
        $this->checkCurrency($columns->cell($row, 'currency'), $error);
        $capturedAt = $this->capture($columns->cell($row, 'captured_at'), $error, $warning);
        $source = strtolower($columns->cell($row, 'source'));
        $source = $source === '' ? 'csv' : $source;
        if (preg_match(self::SOURCE_PATTERN, $source) !== 1) {
            $error(MarketCsvProblem::BAD_SOURCE, 'source');
        }
        $available = $columns->cell($row, 'available') === '' ? true : CsvValues::flag($columns->cell($row, 'available'));
        if ($available === null) {
            $error(MarketCsvProblem::BAD_AVAILABLE, 'available');
        }
        if (self::hasError($problems, $before) || $competitorId === null || $checkIn === null || $price === null || $basis === null || $capturedAt === null || $available === null) {
            $problems = [...array_slice($problems, 0, $before), ...array_filter(array_slice($problems, $before), static fn (MarketCsvProblem $p): bool => $p->kind === MarketCsvProblem::ERROR)];

            return null;
        }

        $total = $perNight ? null : $price;
        $nightly = $perNight ? $price : Num::roundHalfEven($price / $nights, 2);

        return new ParsedRow(
            $line,
            $competitorId,
            $checkIn,
            $nights,
            $basis,
            $nightly,
            $total,
            $this->context->currency,
            $capturedAt,
            $source,
            $available,
            ParsedRow::dedupKey($this->context->propertyId, $competitorId, $checkIn, $nights, $basis, $source, $capturedAt),
        );
    }

    /**
     * @param list<string|null> $row
     *
     * @return array{0: DateTimeImmutable|null, 1: int}
     */
    private function stay(CsvColumns $columns, array $row, callable $error): array
    {
        $checkIn = CsvValues::date($columns->cell($row, 'check_in'));
        $checkOut = CsvValues::date($columns->cell($row, 'check_out'));
        if ($checkIn === null) {
            $error(MarketCsvProblem::BAD_DATE, 'check_in');
        }
        if ($checkOut === null) {
            $error(MarketCsvProblem::BAD_DATE, 'check_out');
        }
        if ($checkIn === null || $checkOut === null) {
            return [null, 0];
        }
        $nights = Days::diff($checkIn, $checkOut);
        if ($nights < 1) {
            $error(MarketCsvProblem::CHECKOUT_NOT_AFTER_CHECKIN, 'check_out');

            return [null, 0];
        }
        if ($nights > $this->context->maxStayNights) {
            $error(MarketCsvProblem::STAY_TOO_LONG, 'check_out');

            return [null, 0];
        }
        if ($checkIn < $this->context->today) {
            $error(MarketCsvProblem::STAY_IN_PAST, 'check_in');

            return [null, 0];
        }

        return [$checkIn, $nights];
    }

    private function checkCurrency(string $currency, callable $error): void
    {
        if ($currency === '') {
            return;
        }
        if (preg_match('/^[A-Za-z]{3}$/', $currency) !== 1) {
            $error(MarketCsvProblem::BAD_CURRENCY, 'currency');
        } elseif (strtoupper($currency) !== $this->context->currency) {
            $error(MarketCsvProblem::CURRENCY_MISMATCH, 'currency');
        }
    }

    private function capture(string $text, callable $error, callable $warning): ?DateTimeImmutable
    {
        if ($text === '') {
            $warning(MarketCsvProblem::CAPTURE_MISSING, 'captured_at');

            return $this->context->now;
        }
        $instant = CsvValues::capture($text, $this->context->timezone);
        if ($instant === null) {
            $error(MarketCsvProblem::BAD_CAPTURE, 'captured_at');

            return null;
        }
        if ($instant->getTimestamp() > $this->context->now->getTimestamp() + self::FUTURE_SLACK_SECONDS) {
            $error(MarketCsvProblem::CAPTURE_IN_FUTURE, 'captured_at');

            return null;
        }
        if ($instant < $this->context->now->modify(sprintf('-%d days', $this->context->maxSampleAgeDays))) {
            $warning(MarketCsvProblem::CAPTURE_TOO_OLD, 'captured_at');
        }

        return $instant;
    }

    /**
     * @param list<MarketCsvProblem> $problems
     */
    private static function hasError(array $problems, int $from): bool
    {
        foreach (array_slice($problems, $from) as $problem) {
            if ($problem->kind === MarketCsvProblem::ERROR) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<string|null> $row
     */
    private static function isBlank(array $row): bool
    {
        foreach ($row as $cell) {
            if (trim((string) $cell) !== '') {
                return false;
            }
        }

        return true;
    }
}
