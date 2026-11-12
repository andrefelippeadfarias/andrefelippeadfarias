<?php

declare(strict_types=1);

namespace Ritmo\Domain\Relations\Params;

/**
 * The parameters of one relation type.
 */
interface RelationParams
{
    /**
     * The storage form (snake_case keys).
     *
     * @return array<string, mixed>
     */
    public function toArray(): array;
}
