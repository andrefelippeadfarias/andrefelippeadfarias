<?php

declare(strict_types=1);

namespace Ritmo\Tools\Package;

use FilesystemIterator;
use RuntimeException;

/**
 * What goes into the distributed zip: every file of the plugin folder except what .distignore
 * leaves out and every hidden file or folder (a name starting with a dot), always.
 *
 * .distignore, one pattern per line ("#" starts a comment):
 *   /docs/PLANO.md   a leading "/" anchors the pattern at the plugin folder
 *   /tests/         a trailing "/" matches a folder (and so everything inside it)
 *   *.log           without a "/" (other than a trailing one) the pattern matches a name at any depth
 *   "*" and "?" match inside one name; "**" matches across folders. "!" (re-include) is refused.
 */
final class DistIgnore
{
    /** @var list<array{regex: string, dir: bool}> */
    private array $rules = [];

    /**
     * @param list<string> $patterns
     */
    public function __construct(array $patterns)
    {
        foreach ($patterns as $raw) {
            $pattern = trim($raw);
            if ($pattern === '' || str_starts_with($pattern, '#')) {
                continue;
            }
            if (str_starts_with($pattern, '!')) {
                throw new RuntimeException("Re-including a path (\"$pattern\") is not supported in .distignore.");
            }
            $dir = str_ends_with($pattern, '/');
            $pattern = rtrim($pattern, '/');
            $anchored = str_contains($pattern, '/');
            $pattern = ltrim($pattern, '/');
            if ($pattern === '') {
                throw new RuntimeException('A .distignore pattern matches the whole plugin folder.');
            }
            $this->rules[] = ['regex' => '#^' . ($anchored ? '' : '(?:.*/)?') . self::glob($pattern) . '$#', 'dir' => $dir];
        }
    }

    public static function fromFile(string $file): self
    {
        if (!is_file($file)) {
            throw new RuntimeException("$file is missing: the build needs it to leave out what is not distributed.");
        }

        return new self(file($file, FILE_IGNORE_NEW_LINES) ?: []);
    }

    /**
     * Whether a path (relative to the plugin folder, "/" separated, without a leading "/") stays
     * out of the package.
     */
    public function excludes(string $relative, bool $isDir): bool
    {
        $relative = trim(str_replace('\\', '/', $relative), '/');
        $parts = explode('/', $relative);
        foreach ($parts as $part) {
            if ($part === '' || $part === '.' || $part === '..') {
                return true;
            }
            if (str_starts_with($part, '.')) {
                return true;
            }
        }
        // A file is out when it, or a folder above it, matches.
        $prefix = '';
        foreach ($parts as $i => $part) {
            $prefix = $prefix === '' ? $part : $prefix . '/' . $part;
            $folder = $i < count($parts) - 1 || $isDir;
            foreach ($this->rules as $rule) {
                if (($folder || !$rule['dir']) && preg_match($rule['regex'], $prefix) === 1) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * The files of the package, relative to $root, sorted. A symbolic link refuses the build: the
     * zip must hold real files only.
     *
     * @return list<string>
     */
    public function files(string $root): array
    {
        $root = rtrim($root, '/');
        $out = [];
        $this->walk($root, '', $out);
        sort($out, SORT_STRING);

        return $out;
    }

    /**
     * @param list<string> $out
     */
    private function walk(string $root, string $relative, array &$out): void
    {
        $dir = $relative === '' ? $root : $root . '/' . $relative;
        foreach (new FilesystemIterator($dir, FilesystemIterator::SKIP_DOTS) as $entry) {
            $path = ($relative === '' ? '' : $relative . '/') . $entry->getFilename();
            $isDir = $entry->isDir() && !$entry->isLink();
            if ($this->excludes($path, $isDir)) {
                continue;
            }
            if ($entry->isLink()) {
                throw new RuntimeException("$path is a symbolic link: the package holds real files only.");
            }
            if ($isDir) {
                $this->walk($root, $path, $out);
            } elseif ($entry->isFile()) {
                $out[] = $path;
            }
        }
    }

    private static function glob(string $pattern): string
    {
        $out = '';
        $length = strlen($pattern);
        for ($i = 0; $i < $length; ++$i) {
            $c = $pattern[$i];
            if ($c === '*' && ($pattern[$i + 1] ?? '') === '*') {
                $out .= '.*';
                ++$i;
            } elseif ($c === '*') {
                $out .= '[^/]*';
            } elseif ($c === '?') {
                $out .= '[^/]';
            } else {
                $out .= preg_quote($c, '#');
            }
        }

        return $out;
    }
}
