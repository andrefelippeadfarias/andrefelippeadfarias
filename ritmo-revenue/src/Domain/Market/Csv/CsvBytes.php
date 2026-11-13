<?php

declare(strict_types=1);

namespace Ritmo\Domain\Market\Csv;

/**
 * Checks of the raw bytes of an uploaded price file, before any row is read: it must look like
 * text (no NUL byte, not a script, page, archive or PDF), be UTF-8 (a BOM is dropped) or
 * Windows-1252 (converted), and its delimiter is the one that splits the header into the most
 * columns.
 */
final class CsvBytes
{
    /** How much of the file the sniff reads. */
    public const HEAD_BYTES = 8192;

    public const BOM = "\xEF\xBB\xBF";

    private const NOT_TEXT_PREFIXES = ['<?', '<html', '<!doctype', '<script', "PK\x03\x04", '%PDF'];

    private const DELIMITERS = [',', ';', "\t"];

    private function __construct()
    {
    }

    /**
     * True when the start of the file cannot be a CSV text file.
     */
    public static function looksBinaryOrCode(string $head): bool
    {
        if (str_contains($head, "\0")) {
            return true;
        }
        $start = strtolower(ltrim(self::withoutBom($head)));
        foreach (self::NOT_TEXT_PREFIXES as $prefix) {
            if (str_starts_with($start, strtolower($prefix))) {
                return true;
            }
        }

        return false;
    }

    public static function withoutBom(string $text): string
    {
        return str_starts_with($text, self::BOM) ? substr($text, 3) : $text;
    }

    /**
     * True when the bytes are valid UTF-8 (a multi-byte character cut at the end of a head read
     * is tolerated).
     */
    public static function isUtf8(string $bytes, bool $truncated = false): bool
    {
        if (mb_check_encoding($bytes, 'UTF-8')) {
            return true;
        }
        if (!$truncated) {
            return false;
        }
        for ($cut = 1; $cut <= 3 && $cut < strlen($bytes); ++$cut) {
            if (mb_check_encoding(substr($bytes, 0, -$cut), 'UTF-8')) {
                return true;
            }
        }

        return false;
    }

    /**
     * The delimiter that gives the header line the most columns (quotes respected); "," on a tie.
     */
    public static function delimiter(string $headerLine): string
    {
        $best = ',';
        $bestCount = 0;
        foreach (self::DELIMITERS as $delimiter) {
            $count = count(str_getcsv(self::withoutBom($headerLine), $delimiter, '"', ''));
            if ($count > $bestCount) {
                $best = $delimiter;
                $bestCount = $count;
            }
        }

        return $best;
    }

    /**
     * The first line of a text (without its line break).
     */
    public static function firstLine(string $text): string
    {
        $end = strcspn($text, "\r\n");

        return substr($text, 0, $end);
    }
}
