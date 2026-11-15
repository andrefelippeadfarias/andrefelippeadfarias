<?php

declare(strict_types=1);

namespace Ritmo\Tools\Package;

use Ritmo\Tools\I18n\Po;

/**
 * What must agree before a release is built: the version in the plugin header, the RITMO_VERSION
 * constant, the "Stable tag" of readme.txt, an entry of the version in both changelogs, the same
 * WordPress and PHP requirements in the header and in readme.txt; and a translation of every text.
 */
final class ReleaseInfo
{
    public const MAIN_FILE = 'ritmo-revenue.php';

    public const SLUG = 'ritmo-revenue';

    private const SEMVER = '/^\d+\.\d+\.\d+(?:-[0-9A-Za-z.]+)?$/';

    private function __construct()
    {
    }

    /**
     * The version of the plugin header ("" when there is none).
     */
    public static function version(string $root): string
    {
        return self::header((string) @file_get_contents($root . '/' . self::MAIN_FILE), 'Version');
    }

    /**
     * @return list<string> what does not agree; empty when the release may be built
     */
    public static function problems(string $root): array
    {
        $main = (string) @file_get_contents($root . '/' . self::MAIN_FILE);
        $readme = (string) @file_get_contents($root . '/readme.txt');
        $changelog = (string) @file_get_contents($root . '/CHANGELOG.md');
        $out = [];
        if ($main === '') {
            return [self::MAIN_FILE . ' is missing.'];
        }
        $version = self::header($main, 'Version');
        if (preg_match(self::SEMVER, $version) !== 1) {
            $out[] = sprintf('The plugin header has no version of the form 1.2.3 ("%s").', $version);
        }
        $constant = preg_match('/define\(\s*[\'"]RITMO_VERSION[\'"]\s*,\s*[\'"]([^\'"]+)[\'"]\s*\)/', $main, $m) === 1 ? $m[1] : '';
        if ($constant !== $version) {
            $out[] = sprintf('RITMO_VERSION is "%s" but the plugin header says "%s".', $constant, $version);
        }
        if (self::header($main, 'Text Domain') !== self::SLUG) {
            $out[] = 'The plugin header does not name the text domain ' . self::SLUG . '.';
        }
        if ($readme === '') {
            $out[] = 'readme.txt is missing.';
        } else {
            $stable = self::header($readme, 'Stable tag');
            if ($stable !== $version) {
                $out[] = sprintf('The "Stable tag" of readme.txt is "%s" but the plugin header says "%s".', $stable, $version);
            }
            foreach (['Requires at least', 'Requires PHP'] as $field) {
                if (self::header($readme, $field) !== self::header($main, $field) || self::header($main, $field) === '') {
                    $out[] = sprintf('"%s" differs between the plugin header ("%s") and readme.txt ("%s").', $field, self::header($main, $field), self::header($readme, $field));
                }
            }
            if (preg_match('/^=== .+ ===\s*$/m', $readme) !== 1) {
                $out[] = 'readme.txt does not start with "=== Plugin Name ===".';
            }
            foreach (['Description', 'Installation', 'Frequently Asked Questions', 'Changelog'] as $section) {
                if (preg_match('/^== ' . preg_quote($section, '/') . ' ==\s*$/m', $readme) !== 1) {
                    $out[] = "readme.txt has no \"== $section ==\" section.";
                }
            }
            if ($version !== '' && preg_match('/^= ' . preg_quote($version, '/') . '\b.*=\s*$/m', $readme) !== 1) {
                $out[] = "The changelog of readme.txt has no entry \"= $version =\".";
            }
        }
        if ($changelog === '') {
            $out[] = 'CHANGELOG.md is missing.';
        } elseif ($version !== '' && preg_match('/^##\s+\[?' . preg_quote($version, '/') . '\]?(?:\s|$)/m', $changelog) !== 1) {
            $out[] = "CHANGELOG.md has no entry \"## $version\".";
        }

        return $out;
    }

    /**
     * A header field of the plugin file or of readme.txt ("Version: 1.0.0", " * Requires PHP: 8.1").
     */
    public static function header(string $text, string $field): string
    {
        $head = substr($text, 0, 8192);

        return preg_match('/^[ \t\/*#@]*' . preg_quote($field, '/') . ':[ \t]*(.*?)[ \t]*(?:\*\/)?$/mi', $head, $m) === 1 ? trim($m[1]) : '';
    }

    /**
     * Texts of the .pot that a .po does not translate (missing, empty, fuzzy, or with other
     * placeholders), at most $limit of them.
     *
     * @return list<string>
     */
    public static function untranslated(string $pot, string $po, int $limit = 20): array
    {
        $wanted = self::keyed(Po::parse($pot));
        $have = self::keyed(Po::parse($po));
        $out = [];
        foreach ($wanted as $key => $entry) {
            $label = '"' . (strlen($entry['singular']) > 70 ? substr($entry['singular'], 0, 67) . '...' : $entry['singular']) . '"';
            $translated = $have[$key] ?? null;
            if ($translated === null) {
                $out[] = "$label is not in the translation";
            } elseif ($translated['fuzzy']) {
                $out[] = "$label is marked fuzzy";
            } else {
                $sources = $entry['plural'] === null ? [$entry['singular']] : [$entry['singular'], $entry['plural']];
                if (count($translated['translations']) < count($sources) || in_array('', $translated['translations'], true)) {
                    $out[] = "$label is not translated";
                } else {
                    foreach ($translated['translations'] as $i => $text) {
                        if (self::placeholders($sources[min($i, count($sources) - 1)]) !== self::placeholders($text)) {
                            $out[] = "$label has other placeholders in the translation";
                            break;
                        }
                    }
                }
            }
            if (count($out) >= $limit) {
                break;
            }
        }

        return $out;
    }

    /**
     * @param list<array<string, mixed>> $entries
     *
     * @return array<string, array<string, mixed>>
     */
    private static function keyed(array $entries): array
    {
        $out = [];
        foreach ($entries as $entry) {
            if ($entry['singular'] !== '') {
                $out[($entry['context'] ?? '') . "\4" . $entry['singular']] = $entry;
            }
        }

        return $out;
    }

    /**
     * @return list<string>
     */
    private static function placeholders(string $text): array
    {
        preg_match_all('/%(\d+\$)?[sdf%]/', $text, $m);
        sort($m[0]);

        return $m[0];
    }
}
