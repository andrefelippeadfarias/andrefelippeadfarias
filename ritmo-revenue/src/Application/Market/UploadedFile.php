<?php

declare(strict_types=1);

namespace Ritmo\Application\Market;

/**
 * What the import keeps of an uploaded price file: its sanitised name, hash and size, and its
 * records already split into cells. The file itself is never kept.
 */
final class UploadedFile
{
    /**
     * @param list<list<string|null>> $records header first
     */
    public function __construct(
        public readonly string $name,
        public readonly string $sha256,
        public readonly int $bytes,
        public readonly array $records,
    ) {
    }
}
