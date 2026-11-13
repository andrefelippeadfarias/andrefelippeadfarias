<?php

declare(strict_types=1);

namespace Ritmo\Tools\I18n;

use RuntimeException;

/**
 * Reading and writing the gettext .po format, as far as the plugin's files need it: entries with
 * an optional context, a singular, an optional plural, the translations, the "fuzzy" flag and
 * comments; strings on one or several quoted lines.
 */
final class Po
{
    private function __construct()
    {
    }

    public static function quote(string $text): string
    {
        return '"' . strtr($text, ['\\' => '\\\\', '"' => '\\"', "\n" => '\\n', "\t" => '\\t', "\r" => '\\r']) . '"';
    }

    /**
     * @return list<array{context: ?string, singular: string, plural: ?string, translations: list<string>, fuzzy: bool, comments: list<string>}>
     *
     * @throws RuntimeException on a line that is not of the format
     */
    public static function parse(string $text): array
    {
        $entries = [];
        $entry = self::blank();
        $field = null;
        foreach (preg_split('/\r?\n/', $text) ?: [] as $number => $line) {
            $line = trim($line);
            if ($line === '') {
                $entries = self::close($entries, $entry);
                $entry = self::blank();
                $field = null;
                continue;
            }
            if (str_starts_with($line, '#,')) {
                $entry['fuzzy'] = $entry['fuzzy'] || str_contains($line, 'fuzzy');
                continue;
            }
            if (str_starts_with($line, '#')) {
                $entry['comments'][] = $line;
                continue;
            }
            if (preg_match('/^(msgctxt|msgid_plural|msgid|msgstr(?:\[(\d+)\])?)\s+(".*")$/', $line, $m) === 1) {
                if ($m[1] === 'msgid' && $entry['singular'] !== null) {
                    $entries = self::close($entries, $entry);
                    $entry = self::blank();
                }
                $field = isset($m[2]) && $m[2] !== '' ? 'msgstr' . $m[2] : $m[1];
                $entry['fields'][$field] = self::unquote($m[3], $number + 1);
                if ($field === 'msgid') {
                    $entry['singular'] = $entry['fields'][$field];
                }
                continue;
            }
            if ($line[0] === '"' && $field !== null) {
                $entry['fields'][$field] .= self::unquote($line, $number + 1);
                if ($field === 'msgid') {
                    $entry['singular'] = $entry['fields'][$field];
                }
                continue;
            }
            throw new RuntimeException(sprintf('Line %d is not of the .po format.', $number + 1));
        }

        return self::close($entries, $entry);
    }

    /**
     * @return array{singular: ?string, fields: array<string, string>, fuzzy: bool, comments: list<string>}
     */
    private static function blank(): array
    {
        return ['singular' => null, 'fields' => [], 'fuzzy' => false, 'comments' => []];
    }

    /**
     * @param list<array<string, mixed>>                                                                $entries
     * @param array{singular: ?string, fields: array<string, string>, fuzzy: bool, comments: list<string>} $entry
     *
     * @return list<array<string, mixed>>
     */
    private static function close(array $entries, array $entry): array
    {
        if (!isset($entry['fields']['msgid'])) {
            return $entries;
        }
        $f = $entry['fields'];
        $translations = isset($f['msgstr']) ? [$f['msgstr']] : [];
        for ($i = 0; isset($f['msgstr' . $i]); ++$i) {
            $translations[] = $f['msgstr' . $i];
        }
        $entries[] = [
            'context' => $f['msgctxt'] ?? null,
            'singular' => $f['msgid'],
            'plural' => $f['msgid_plural'] ?? null,
            'translations' => $translations,
            'fuzzy' => $entry['fuzzy'],
            'comments' => $entry['comments'],
        ];

        return $entries;
    }

    private static function unquote(string $quoted, int $line): string
    {
        if (strlen($quoted) < 2 || $quoted[0] !== '"' || $quoted[strlen($quoted) - 1] !== '"') {
            throw new RuntimeException(sprintf('Line %d has a string that is not quoted.', $line));
        }

        return (string) preg_replace_callback('/\\\\(.)/', static fn (array $m): string => ['n' => "\n", 't' => "\t", 'r' => "\r", '"' => '"', '\\' => '\\'][$m[1]] ?? $m[1], substr($quoted, 1, -1));
    }
}
