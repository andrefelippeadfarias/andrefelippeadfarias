<?php

declare(strict_types=1);

namespace Ritmo\Application\Setup;

use DateTimeImmutable;
use InvalidArgumentException;
use Ritmo\Domain\Support\Days;

/**
 * Reads competitor prices pasted as simple CSV: one "date,price" per line (YYYY-MM-DD and the
 * public nightly price). A header line, blank lines and lines starting with "#" are skipped. With
 * ";" or a tab between the fields the price may use a decimal comma.
 */
final class SampleCsv
{
    public const MAX_LINES = 5000;

    private function __construct()
    {
    }

    /**
     * @return list<array{night: DateTimeImmutable, price: float}>
     *
     * @throws InvalidArgumentException naming the first line that cannot be read
     */
    public static function parse(string $text): array
    {
        $lines = preg_split('/\R/', $text) ?: [];
        if (count($lines) > self::MAX_LINES) {
            throw new InvalidArgumentException(sprintf('Paste at most %d lines at a time.', self::MAX_LINES));
        }

        $rows = [];
        foreach ($lines as $index => $raw) {
            $line = trim($raw);
            if ($line === '' || str_starts_with($line, '#') || ($rows === [] && preg_match('/^\d/', $line) !== 1)) {
                continue;
            }
            $rows[] = self::row($line, $index + 1);
        }
        if ($rows === []) {
            throw new InvalidArgumentException('No price was found: write one "date,price" per line.');
        }

        return $rows;
    }

    /**
     * @return array{night: DateTimeImmutable, price: float}
     */
    private static function row(string $line, int $number): array
    {
        $delimiter = str_contains($line, ';') ? ';' : (str_contains($line, "\t") ? "\t" : ',');
        $fields = array_map('trim', explode($delimiter, $line));
        if (count($fields) !== 2) {
            throw new InvalidArgumentException(sprintf('Line %d: write exactly a date and a price.', $number));
        }
        [$date, $price] = $fields;
        if ($delimiter !== ',') {
            $price = str_replace(',', '.', $price);
        }

        try {
            $night = Days::date($date);
        } catch (InvalidArgumentException) {
            throw new InvalidArgumentException(sprintf('Line %d: the date must be written as YYYY-MM-DD.', $number));
        }
        if (preg_match('/^\d{1,7}(\.\d{1,2})?$/', $price) !== 1 || (float) $price <= 0.0) {
            throw new InvalidArgumentException(sprintf('Line %d: the price must be a positive number such as 480 or 480.50.', $number));
        }

        return ['night' => $night, 'price' => (float) $price];
    }
}
