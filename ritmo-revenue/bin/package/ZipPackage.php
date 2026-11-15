<?php

declare(strict_types=1);

namespace Ritmo\Tools\Package;

use RuntimeException;
use ZipArchive;

/**
 * Writes and checks the distributed zip: one folder (the plugin slug) holding the staged files,
 * sorted, with fixed permissions (0644 files, 0755 folders) and one timestamp, so that the same
 * sources and the same timestamp give the same bytes (and the same SHA-256).
 */
final class ZipPackage
{
    /** What a package that WordPress can install and run must hold, relative to the plugin folder. */
    /** The largest zip: under the 2 MB upload limit (upload_max_filesize = 2M) of PHP and many hosts. */
    public const MAX_BYTES = 2000000;

    public const REQUIRED = [
        'ritmo-revenue.php',
        'uninstall.php',
        'readme.txt',
        'src/autoload.php',
        'src/Plugin.php',
        'presets/default.json',
        'languages/ritmo-revenue-pt_BR.mo',
        'languages/ritmo-revenue-pt_BR.l10n.php',
    ];

    private function __construct()
    {
    }

    /**
     * @param list<string> $files relative to $stage
     *
     * @return int the number of files written
     */
    public static function write(string $stage, array $files, string $zipPath, string $folder, int $mtime): int
    {
        if (!class_exists(ZipArchive::class)) {
            throw new RuntimeException('PHP has no zip extension (ZipArchive): install php-zip to build the package.');
        }
        if (is_file($zipPath) && !unlink($zipPath)) {
            throw new RuntimeException("Cannot replace $zipPath.");
        }
        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::EXCL) !== true) {
            throw new RuntimeException("Cannot create $zipPath.");
        }
        sort($files, SORT_STRING);
        $folders = [$folder . '/' => true];
        foreach ($files as $relative) {
            $parts = explode('/', $relative);
            array_pop($parts);
            $path = $folder;
            foreach ($parts as $part) {
                $path .= '/' . $part;
                $folders[$path . '/'] = true;
            }
        }
        ksort($folders, SORT_STRING);
        foreach (array_keys($folders) as $dir) {
            $name = rtrim($dir, '/');
            $zip->addEmptyDir($name);
            self::stamp($zip, $name . '/', 040755, $mtime);
        }
        foreach ($files as $relative) {
            $name = $folder . '/' . $relative;
            if (!$zip->addFile($stage . '/' . $relative, $name)) {
                throw new RuntimeException("Cannot add $relative to the zip.");
            }
            $zip->setCompressionName($name, ZipArchive::CM_DEFLATE, 9);
            self::stamp($zip, $name, 0100644, $mtime);
        }
        if (!$zip->close()) {
            throw new RuntimeException("Cannot write $zipPath.");
        }

        return count($files);
    }

    /**
     * The names inside a zip, in the order they are stored.
     *
     * @return list<string>
     */
    public static function entries(string $zipPath): array
    {
        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::RDONLY) !== true) {
            throw new RuntimeException("Cannot open $zipPath.");
        }
        $out = [];
        for ($i = 0; $i < $zip->numFiles; ++$i) {
            $out[] = (string) $zip->getNameIndex($i);
        }
        $zip->close();

        return $out;
    }

    /**
     * What is wrong with a built zip: the plugin folder, the files a site needs, nothing the
     * .distignore leaves out, no path that climbs out, no symbolic link, every file readable back.
     *
     * @param list<string> $expected the staged files, relative to the plugin folder
     *
     * @return list<string>
     */
    public static function problems(string $zipPath, string $folder, DistIgnore $rules, array $expected): array
    {
        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::RDONLY | ZipArchive::CHECKCONS) !== true) {
            return ["$zipPath is not a readable zip."];
        }
        $out = [];
        $files = [];
        for ($i = 0; $i < $zip->numFiles; ++$i) {
            $name = (string) $zip->getNameIndex($i);
            if (!str_starts_with($name, $folder . '/')) {
                $out[] = "$name is outside the folder $folder/.";
                continue;
            }
            if (str_contains($name, '..') || str_contains($name, '\\') || str_starts_with($name, '/')) {
                $out[] = "$name is not a plain relative path.";
            }
            $zip->getExternalAttributesIndex($i, $system, $attributes);
            if ($system === ZipArchive::OPSYS_UNIX && (($attributes >> 16) & 0170000) === 0120000) {
                $out[] = "$name is a symbolic link.";
            }
            $relative = substr($name, strlen($folder) + 1);
            if ($relative === '' || str_ends_with($name, '/')) {
                continue;
            }
            if ($rules->excludes($relative, false)) {
                $out[] = "$relative is left out by .distignore but is in the zip.";
            }
            if ($zip->getFromIndex($i) === false) {
                $out[] = "$relative cannot be read back.";
            }
            $files[] = $relative;
        }
        $zip->close();
        foreach (self::REQUIRED as $required) {
            if (!in_array($required, $files, true)) {
                $out[] = "$required is missing from the zip.";
            }
        }
        $size = (int) @filesize($zipPath);
        if ($size > self::MAX_BYTES) {
            $out[] = sprintf('The zip has %d bytes, over the %d that a host with the default upload limit (2 MB) accepts.', $size, self::MAX_BYTES);
        }
        sort($files, SORT_STRING);
        $sorted = $expected;
        sort($sorted, SORT_STRING);
        if ($files !== $sorted) {
            $out[] = sprintf('The zip holds %d files, the staged package %d.', count($files), count($sorted));
        }

        return $out;
    }

    private static function stamp(ZipArchive $zip, string $name, int $mode, int $mtime): void
    {
        $zip->setExternalAttributesName($name, ZipArchive::OPSYS_UNIX, $mode << 16);
        $zip->setMtimeName($name, $mtime);
    }
}
