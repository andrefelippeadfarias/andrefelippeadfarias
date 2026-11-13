<?php

declare(strict_types=1);

namespace Ritmo\Admin\Components;

/**
 * CSV downloads: UTF-8 with a BOM, comma separated, dot decimals, ISO dates, streamed to the
 * response (never built on disk). Text cells are always quoted; numbers the writer formats itself
 * stay bare numbers (the exports carry before and after amounts, never negative differences).
 * Formula guard: a text cell that a spreadsheet would read as a formula (it starts with = + - @,
 * a tab or a carriage return) gets a leading apostrophe, and so does every part of it that starts
 * like one after a separator (, ; tab or a line break, spaces allowed): a spreadsheet that opens a
 * comma file splitting at semicolons must never find a formula cell either.
 */
final class CsvWriter
{
    private const FORMULA_START = ['=', '+', '-', '@', "\t", "\r"];

    /** A separator a spreadsheet may split at, then optional spaces, then a formula start. */
    private const FORMULA_AFTER_SEPARATOR = '/([,;\t\r\n])([ ]*)(?=[=+\-@])/';

    private function __construct()
    {
    }

    /**
     * @param list<string>                         $header
     * @param iterable<list<string|int|float|null>> $rows
     */
    public static function send(string $filename, array $header, iterable $rows): void
    {
        nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . self::filename($filename) . '"');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: no-store');
        $out = fopen('php://output', 'wb');
        if ($out === false) {
            return;
        }
        fwrite($out, "\xEF\xBB\xBF");
        self::write($out, $header, $rows);
        fclose($out);
    }

    /**
     * Writes the header and the rows to an open stream (the response, or memory in the tests).
     *
     * @param resource                              $stream
     * @param list<string>                          $header
     * @param iterable<list<string|int|float|null>> $rows
     */
    public static function write($stream, array $header, iterable $rows): void
    {
        fwrite($stream, self::line($header));
        foreach ($rows as $row) {
            fwrite($stream, self::line($row));
        }
    }

    /**
     * The value as written in its cell, before quoting: a number as the writer formats it, a
     * text with the formula guard.
     */
    public static function cell(string|int|float|null $value): string
    {
        if ($value === null) {
            return '';
        }
        if (is_int($value)) {
            return (string) $value;
        }
        if (is_float($value)) {
            return rtrim(rtrim(sprintf('%.2F', $value), '0'), '.');
        }
        $guarded = (string) preg_replace(self::FORMULA_AFTER_SEPARATOR, "$1$2'", $value);

        return $guarded !== '' && in_array($guarded[0], self::FORMULA_START, true) ? "'" . $guarded : $guarded;
    }

    /**
     * @param list<string|int|float|null> $row
     */
    private static function line(array $row): string
    {
        return implode(',', array_map(static fn (string|int|float|null $v): string => is_string($v) ? '"' . str_replace('"', '""', self::cell($v)) . '"' : self::cell($v), $row)) . "\n";
    }

    /** An ASCII file name from codes and dates only. */
    public static function filename(string $name): string
    {
        $clean = (string) preg_replace('/[^A-Za-z0-9._-]+/', '-', $name);

        return $clean === '' ? 'export.csv' : $clean;
    }
}
