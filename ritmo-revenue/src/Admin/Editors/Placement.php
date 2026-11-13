<?php

declare(strict_types=1);

namespace Ritmo\Admin\Editors;

/**
 * Where a rule-set field is edited: its tab, its section and whether it is an advanced setting.
 */
final class Placement
{
    public function __construct(public readonly string $tab, public readonly string $section, public readonly bool $advanced)
    {
    }
}
