<?php

declare(strict_types=1);

namespace Ritmo\Application\Rules;

/**
 * One value that differs between two rule sets: its concrete path ("floors.owner.1.amount"), the
 * schema key it falls under ("floors.owner.*.amount", null for a section the schema does not
 * know yet), and the values before and after. A list item added or removed is one change whose
 * other side is null.
 */
final class FieldChange
{
    public const CHANGED = 'changed';

    public const ADDED = 'added';

    public const REMOVED = 'removed';

    public function __construct(
        public readonly string $path,
        public readonly ?string $key,
        public readonly string $kind,
        public readonly mixed $before,
        public readonly mixed $after,
    ) {
    }
}
