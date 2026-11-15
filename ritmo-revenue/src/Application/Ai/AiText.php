<?php

declare(strict_types=1);

namespace Ritmo\Application\Ai;

/**
 * A text the assistant wrote and the plugin checked, with the model that answered and whether
 * the fallback answered.
 */
final class AiText
{
    public function __construct(public readonly string $text, public readonly string $model, public readonly bool $fallback)
    {
    }
}
