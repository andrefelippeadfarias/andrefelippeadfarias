<?php

declare(strict_types=1);

namespace Ritmo\Admin\Market;

use Closure;
use Ritmo\Application\Market\MarketRefused;
use Ritmo\Application\Market\UploadedFile;
use Ritmo\Domain\Market\Csv\CsvBytes;
use Ritmo\Domain\Market\Csv\CsvColumns;
use Ritmo\Domain\Market\Csv\MarketCsvProblem;

/**
 * Reads an uploaded market price file in place (spec 05, 6.4 and spec 06, 1.2): a real upload,
 * not larger than the limit (checked before any read), named .csv and accepted as CSV text by
 * WordPress, not binary nor code, UTF-8 (a BOM is dropped) or Windows-1252 (converted); then its
 * records with fgetcsv and the delimiter the header uses. The file is never moved, copied, kept
 * or served back: PHP removes it at the end of the request.
 */
final class CsvUploadReader
{
    /** Records read at most: the row limit, the header and a margin for blank lines. */
    private const RECORD_MARGIN = 100;

    /**
     * @param Closure(string): bool         $isUploaded is_uploaded_file()
     * @param Closure(string, string): bool $isCsv      wp_check_filetype_and_ext() accepts the file as CSV text
     */
    public function __construct(private readonly Closure $isUploaded, private readonly Closure $isCsv)
    {
    }

    /**
     * @param array<string, mixed> $file an entry of $_FILES
     *
     * @throws MarketRefused (file_error, with the problem code) when the file cannot be read
     */
    public function read(array $file, int $maxBytes, int $maxRows): UploadedFile
    {
        $tmp = is_string($file['tmp_name'] ?? null) ? $file['tmp_name'] : '';
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || $tmp === '' || !($this->isUploaded)($tmp)) {
            throw self::refused(($file['error'] ?? null) === UPLOAD_ERR_INI_SIZE || ($file['error'] ?? null) === UPLOAD_ERR_FORM_SIZE ? MarketCsvProblem::FILE_TOO_LARGE : MarketCsvProblem::EMPTY_FILE);
        }
        $bytes = (int) filesize($tmp);
        if ((int) ($file['size'] ?? 0) > $maxBytes || $bytes > $maxBytes) {
            throw self::refused(MarketCsvProblem::FILE_TOO_LARGE);
        }
        $name = (string) ($file['name'] ?? '');
        if (strtolower(pathinfo($name, PATHINFO_EXTENSION)) !== 'csv' || !($this->isCsv)($tmp, $name)) {
            throw self::refused(MarketCsvProblem::NOT_CSV);
        }
        if ($bytes === 0) {
            throw self::refused(MarketCsvProblem::EMPTY_FILE);
        }
        $handle = fopen($tmp, 'rb');
        if ($handle === false) {
            throw self::refused(MarketCsvProblem::EMPTY_FILE);
        }
        try {
            $head = (string) fread($handle, CsvBytes::HEAD_BYTES);
            if (CsvBytes::looksBinaryOrCode($head)) {
                throw self::refused(MarketCsvProblem::NOT_CSV);
            }
            if (!CsvBytes::isUtf8(CsvBytes::withoutBom($head), $bytes > CsvBytes::HEAD_BYTES)) {
                fclose($handle);
                $handle = self::converted($tmp);
                $head = (string) fread($handle, CsvBytes::HEAD_BYTES);
            }
            rewind($handle);

            return new UploadedFile(self::cleanName($name), (string) hash_file('sha256', $tmp), $bytes, self::records($handle, CsvBytes::delimiter(CsvBytes::firstLine($head)), $maxRows));
        } finally {
            if (is_resource($handle)) {
                fclose($handle);
            }
        }
    }

    /**
     * A file name safe to show and store: its base name with letters, digits, dots, dashes and
     * underscores only, at most 120 characters.
     */
    public static function cleanName(string $name): string
    {
        $clean = trim((string) preg_replace('/[^A-Za-z0-9._-]+/', '-', basename(str_replace('\\', '/', $name))), '-.');

        return substr($clean === '' ? 'prices.csv' : $clean, 0, 120);
    }

    /**
     * @param resource $handle
     *
     * @return list<list<string|null>>
     */
    private static function records($handle, string $delimiter, int $maxRows): array
    {
        $records = [];
        $limit = $maxRows + self::RECORD_MARGIN;
        while (($row = fgetcsv($handle, 0, $delimiter, '"', '')) !== false) {
            $records[] = array_slice($row, 0, CsvColumns::MAX_COLUMNS + 1);
            if (count($records) > $limit) {
                break;
            }
        }
        if (isset($records[0][0]) && is_string($records[0][0])) {
            $records[0][0] = CsvBytes::withoutBom($records[0][0]);
        }

        return $records;
    }

    /**
     * The file converted from Windows-1252 into a temporary stream in memory.
     *
     * @return resource
     *
     * @throws MarketRefused when the conversion is not available
     */
    private static function converted(string $tmp)
    {
        if (!function_exists('mb_convert_encoding')) {
            throw self::refused(MarketCsvProblem::NOT_UTF8);
        }
        $text = mb_convert_encoding((string) file_get_contents($tmp), 'UTF-8', 'Windows-1252');
        $stream = fopen('php://temp', 'w+b');
        if ($stream === false || !is_string($text) || !mb_check_encoding($text, 'UTF-8')) {
            throw self::refused(MarketCsvProblem::NOT_UTF8);
        }
        fwrite($stream, $text);
        rewind($stream);

        return $stream;
    }

    private static function refused(string $code): MarketRefused
    {
        return new MarketRefused(MarketRefused::FILE_ERROR, '', ['code' => $code]);
    }
}
