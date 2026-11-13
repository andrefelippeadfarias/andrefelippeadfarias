<?php

declare(strict_types=1);

namespace Ritmo\Admin\Components;

/**
 * A tab of a screen: shown only to whoever holds its capability; opened by URL without it, the
 * screen answers "not allowed" (403).
 */
final class Tab
{
    public function __construct(public readonly string $id, public readonly string $title, public readonly string $capability)
    {
    }
}
