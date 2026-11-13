<?php

declare(strict_types=1);

namespace Ritmo\Admin\Components;

/**
 * CSV downloads: UTF-8 with a BOM, comma separated, dot decimals, ISO dates, streamed to the
 * response (never built on disk). Every text cell that a spreadsheet would read as a formula
 * (it starts with = + - @, a tab or a carriage return) gets a leading apostrophe; numbers the
 * writer formats itself stay numbers (the exports carry before and after amounts, never negative
 * differences).
 */
final class CsvWriter
{
    private const FORMULA_START = ['=', '+', '-', '@', "\t", "\r"];

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
        fputcsv($stream, array_map(self::cell(...), $header), ',', '"', '');
        foreach ($rows as $row) {
            fputcsv($stream, array_map(self::cell(...), $row), ',', '"', '');
        }
    }

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

        return $value !== '' && in_array($value[0], self::FORMULA_START, true) ? "'" . $value : $value;
    }

    /** An ASCII file name from codes and dates only. */
    public static function filename(string $name): string
    {
        $clean = (string) preg_replace('/[^A-Za-z0-9._-]+/', '-', $name);

        return $clean === '' ? 'export.csv' : $clean;
    }
}
