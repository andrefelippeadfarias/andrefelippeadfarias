<?php

declare(strict_types=1);

namespace Ritmo\Tests\Unit\Packaging;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * A throwaway folder for the packaging tests.
 */
final class TempTree
{
    private function __construct()
    {
    }

    /**
     * @param array<string, string> $files relative path => content
     */
    public static function make(array $files): string
    {
        $dir = sys_get_temp_dir() . '/ritmo-packaging-' . bin2hex(random_bytes(6));
        mkdir($dir, 0755, true);
        foreach ($files as $relative => $content) {
            $path = $dir . '/' . $relative;
            if (!is_dir(dirname($path))) {
                mkdir(dirname($path), 0755, true);
            }
            file_put_contents($path, $content);
        }

        return $dir;
    }

    public static function remove(string $dir): void
    {
        if (!is_dir($dir) || !str_starts_with(basename($dir), 'ritmo-packaging-')) {
            return;
        }
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $entry) {
            $entry->isDir() && !$entry->isLink() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
        rmdir($dir);
    }
}
